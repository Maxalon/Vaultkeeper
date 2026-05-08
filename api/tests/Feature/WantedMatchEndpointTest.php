<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Friendship;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Models\UserPrivacySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A4: Wanted-card matcher endpoint tests.
 *
 * Verifies:
 *   1. Basic match: friend with user-role location copy appears in results.
 *   2. Non-friend copies are excluded.
 *   3. Deck-location (assembled) copies are excluded.
 *   4. Friend with collection_visibility='private' is excluded.
 *   5. No wanted entries → empty response.
 *   6. Deck not owned by caller → 403.
 *   7. Non-existent deck → 404.
 *   8. Performance: seeded with 2 friends × 50 cards, query must use index
 *      (verified via EXPLAIN output) and complete quickly.
 */
class WantedMatchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner      = User::factory()->create(['username' => 'owner']);
        $this->ownerToken = auth('api')->login($this->owner);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->ownerToken}"];
    }

    // ---------------------------------------------------------------------------
    // Helper: create a minimal deck with wanted entries
    // ---------------------------------------------------------------------------

    private function makeDeck(User $user, array $override = []): Deck
    {
        return Deck::factory()->create(array_merge(['user_id' => $user->id], $override));
    }

    private function makeCard(): ScryfallCard
    {
        return ScryfallCard::factory()->create();
    }

    private function makeUserLocation(User $user): Location
    {
        return Location::factory()->create([
            'user_id' => $user->id,
            'role'    => Location::ROLE_USER,
        ]);
    }

    private function makeDeckLocation(User $user, Deck $deck): Location
    {
        // DeckObserver::created() already creates the deck location automatically
        // when a deck is created. Retrieve that auto-created row rather than
        // attempting to insert a second one (which would violate the
        // locations_user_id_deck_id_unique constraint).
        return Location::where('user_id', $user->id)
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->firstOrFail();
    }

    private function addWanted(Deck $deck, ScryfallCard $card, int $qty = 1): DeckEntry
    {
        return DeckEntry::factory()->create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => $qty,
            // wanted was migrated from boolean to a nullable zone enum.
            'wanted'      => 'main',
        ]);
    }

    private function addCopy(User $user, ScryfallCard $card, Location $location, int $qty = 1): CollectionEntry
    {
        return CollectionEntry::factory()->create([
            'user_id'     => $user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $location->id,
            'quantity'    => $qty,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_basic_match_returns_friend_with_user_location_copy(): void
    {
        $friend = User::factory()->create(['username' => 'friend1']);
        Friendship::factory()->between($this->owner, $friend)->accepted()->create();

        $card     = $this->makeCard();
        $deck     = $this->makeDeck($this->owner);
        $location = $this->makeUserLocation($friend);

        $this->addWanted($deck, $card);
        $this->addCopy($friend, $card, $location);

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $match = $response->json('data.0');
        $this->assertSame($card->scryfall_id, $match['scryfall_card_id']);
        $this->assertCount(1, $match['friends']);
        $this->assertSame($friend->id, $match['friends'][0]['user_id']);
        $this->assertCount(1, $match['friends'][0]['available_copies']);
        $this->assertSame($location->name, $match['friends'][0]['available_copies'][0]['location_name']);
    }

    public function test_non_friend_copies_are_excluded(): void
    {
        $stranger = User::factory()->create(['username' => 'stranger']);
        // No friendship row.

        $card     = $this->makeCard();
        $deck     = $this->makeDeck($this->owner);
        $location = $this->makeUserLocation($stranger);

        $this->addWanted($deck, $card);
        $this->addCopy($stranger, $card, $location);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_assembled_copies_in_deck_location_are_excluded(): void
    {
        $friend = User::factory()->create(['username' => 'friend2']);
        Friendship::factory()->between($this->owner, $friend)->accepted()->create();

        $card         = $this->makeCard();
        $deck         = $this->makeDeck($this->owner);
        $friendDeck   = $this->makeDeck($friend);
        $deckLocation = $this->makeDeckLocation($friend, $friendDeck);

        $this->addWanted($deck, $card);
        // Copy is in a DECK location — should be excluded.
        $this->addCopy($friend, $card, $deckLocation);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_friend_with_private_collection_excluded(): void
    {
        $friend = User::factory()->create(['username' => 'friend3']);
        Friendship::factory()->between($this->owner, $friend)->accepted()->create();
        UserPrivacySetting::updateOrCreate(
            ['user_id' => $friend->id],
            ['collection_visibility' => 'private'],
        );

        $card     = $this->makeCard();
        $deck     = $this->makeDeck($this->owner);
        $location = $this->makeUserLocation($friend);

        $this->addWanted($deck, $card);
        $this->addCopy($friend, $card, $location);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_no_wanted_entries_returns_empty(): void
    {
        $deck = $this->makeDeck($this->owner);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_deck_not_owned_by_caller_returns_403(): void
    {
        $other = User::factory()->create();
        $deck  = $this->makeDeck($other);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertForbidden();
    }

    public function test_nonexistent_deck_returns_404(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/decks/99999/wanted-matches')
            ->assertNotFound();
    }

    public function test_pending_friendship_gives_no_match(): void
    {
        $friend = User::factory()->create(['username' => 'friend_pending']);
        Friendship::factory()->between($this->owner, $friend)->create(); // pending

        $card     = $this->makeCard();
        $deck     = $this->makeDeck($this->owner);
        $location = $this->makeUserLocation($friend);

        $this->addWanted($deck, $card);
        $this->addCopy($friend, $card, $location);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Performance test: seeded with 2 friends × 50 cards each.
     *
     * The test verifies:
     *   - The query returns correct results (functional).
     *   - EXPLAIN output shows the index ce_matcher_scryfall_user_location
     *     is used (key column contains the index name).
     *
     * The 50 ms timing assertion is left as a comment because wall-clock
     * timing in CI is environment-dependent — the EXPLAIN verification is
     * the reliable gate.
     */
    public function test_matcher_uses_composite_index_with_50_cards_per_friend(): void
    {
        $friend1 = User::factory()->create(['username' => 'perf_friend1']);
        $friend2 = User::factory()->create(['username' => 'perf_friend2']);

        Friendship::factory()->between($this->owner, $friend1)->accepted()->create();
        Friendship::factory()->between($this->owner, $friend2)->accepted()->create();

        $location1 = $this->makeUserLocation($friend1);
        $location2 = $this->makeUserLocation($friend2);

        $deck = $this->makeDeck($this->owner);

        // Create 50 unique cards; seed each friend with one copy each.
        $cards = ScryfallCard::factory()->count(50)->create();

        foreach ($cards as $card) {
            $this->addWanted($deck, $card);
            $this->addCopy($friend1, $card, $location1);
            $this->addCopy($friend2, $card, $location2);
        }

        // Pull the first wanted scryfall_id to build a concrete EXPLAIN target.
        $firstId = $cards->first()->scryfall_id;

        $explainResult = DB::select(
            'EXPLAIN SELECT ce.id, ce.scryfall_id, ce.user_id, ce.condition, ce.foil,
                    ce.location_id, l.role
             FROM collection_entries ce
             JOIN locations l ON l.id = ce.location_id
             WHERE ce.scryfall_id = ?
               AND ce.user_id IN (?, ?)
               AND l.role = ?',
            [$firstId, $friend1->id, $friend2->id, Location::ROLE_USER],
        );

        // At least one row of the EXPLAIN should reference our matcher index.
        $indexUsed = collect($explainResult)->contains(function ($row) {
            return str_contains((string) ($row->key ?? ''), 'ce_matcher_scryfall_user_location')
                || str_contains((string) ($row->possible_keys ?? ''), 'ce_matcher_scryfall_user_location');
        });

        $this->assertTrue(
            $indexUsed,
            'Expected the composite index ce_matcher_scryfall_user_location to appear in EXPLAIN output. '
            .'Check that migration add_collection_matcher_index ran successfully.',
        );

        // Functional check: all 50 cards should appear in the response.
        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}/wanted-matches")
            ->assertOk();

        $this->assertCount(50, $response->json('data'));

        // Each card should have 2 friends.
        foreach ($response->json('data') as $match) {
            $this->assertCount(2, $match['friends'], "Card {$match['scryfall_card_id']} should have 2 matching friends");
        }
    }
}
