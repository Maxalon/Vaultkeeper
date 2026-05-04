<?php

namespace Tests\Feature;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/decks/{deck}/wanted — the unified "+1 want one more" endpoint.
 *
 * Drives both the merged-row "+1" button on a purely-bound row (no wanted
 * sibling exists yet, has to mint one) and the catalog-drag drop (which
 * may or may not have an existing wanted sibling to bump). The endpoint
 * never touches a bound sibling — bound CE-backed quantity only changes
 * via the explicit inline-picker "I bought it" path.
 */
class DeckEntryWantedEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Deck $deck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
        $this->deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'Test', 'format' => 'commander',
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_creates_fresh_wanted_entry_when_none_exists(): void
    {
        $card = ScryfallCard::factory()->create(['name' => 'Sol Ring', 'type_line' => 'Artifact']);

        $resp = $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
            ])
            ->assertOk()
            ->assertJsonPath('quantity', 1)
            ->assertJsonPath('wanted', 'main')
            ->assertJsonPath('zone', 'main')
            ->assertJsonPath('physical_copy_id', null);

        $this->assertDatabaseHas('deck_entries', [
            'id'               => $resp->json('id'),
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $card->scryfall_id,
            'zone'             => 'main',
            'quantity'         => 1,
            'wanted'           => 'main',
            'physical_copy_id' => null,
        ]);
    }

    public function test_bumps_existing_wanted_sibling_instead_of_creating_a_new_row(): void
    {
        $card = ScryfallCard::factory()->create();
        $existing = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 2,
            'zone'        => 'main',
            'wanted'      => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
                'delta'       => 3,
            ])
            ->assertOk()
            ->assertJsonPath('id', $existing->id)
            ->assertJsonPath('quantity', 5);

        $this->assertSame(1, DeckEntry::where('deck_id', $this->deck->id)->count(),
            'existing wanted sibling should bump in place, not produce a duplicate row');
    }

    public function test_does_not_touch_bound_sibling_when_creating_wanted_split(): void
    {
        $card = ScryfallCard::factory()->create();
        $deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLocation->id,
            'quantity'    => 4,
        ]);
        $bound = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $card->scryfall_id,
            'quantity'         => 4,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
            ])
            ->assertOk()
            ->assertJsonPath('quantity', 1)
            ->assertJsonPath('wanted', 'main')
            ->assertJsonPath('physical_copy_id', null);

        $bound->refresh();
        $this->assertSame(4, $bound->quantity, 'bound row must not change quantity');
        $this->assertSame($copy->id, $bound->physical_copy_id, 'bound row must keep its physical_copy_id');

        $this->assertSame(2, DeckEntry::where('deck_id', $this->deck->id)
            ->where('scryfall_id', $card->scryfall_id)->count(),
            'should now have one bound row + one wanted sibling');
    }

    public function test_accepts_delta_greater_than_one(): void
    {
        $card = ScryfallCard::factory()->create();

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'side',
                'delta'       => 4,
            ])
            ->assertOk()
            ->assertJsonPath('quantity', 4)
            ->assertJsonPath('wanted', 'side')
            ->assertJsonPath('zone', 'side');
    }

    public function test_explicit_category_overrides_auto_category(): void
    {
        $card = ScryfallCard::factory()->create([
            'type_line' => 'Creature — Bird',
        ]);

        $resp = $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
                'category'    => 'finishers',
            ])
            ->assertOk()
            ->assertJsonPath('category', 'finishers');
    }

    public function test_auto_categorizes_from_type_line_when_no_category_given(): void
    {
        $card = ScryfallCard::factory()->create([
            'type_line' => 'Land — Mountain',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
            ])
            ->assertOk()
            ->assertJsonPath('category', 'land');
    }

    public function test_validates_scryfall_id_exists(): void
    {
        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => '00000000-0000-0000-0000-000000000000',
                'zone'        => 'main',
            ])
            ->assertUnprocessable();
    }

    public function test_validates_zone(): void
    {
        $card = ScryfallCard::factory()->create();
        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'extra',
            ])
            ->assertUnprocessable();
    }

    public function test_rejects_other_users_deck(): void
    {
        $otherUser = User::factory()->create();
        $otherDeck = Deck::create([
            'user_id' => $otherUser->id, 'name' => 'Theirs', 'format' => 'commander',
        ]);
        $card = ScryfallCard::factory()->create();

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$otherDeck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
            ])
            ->assertForbidden();
    }

    public function test_does_not_merge_across_zones(): void
    {
        $card = ScryfallCard::factory()->create();
        $sideWanted = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'side',
            'wanted'      => 'side',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/wanted", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
            ])
            ->assertOk()
            ->assertJsonPath('zone', 'main');

        $this->assertSame(1, $sideWanted->fresh()->quantity, 'side wanted row must not bump');
        $this->assertSame(2, DeckEntry::where('deck_id', $this->deck->id)
            ->where('scryfall_id', $card->scryfall_id)->count(),
            'main and side should stay as two distinct rows');
    }
}
