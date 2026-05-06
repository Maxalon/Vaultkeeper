<?php

namespace Tests\Feature;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the queue-on-shrink path: deleting / unlinking a deck_entry
 * whose bound copy lives in the deck-location flags the copy for review
 * with reason `no_location` (replaces the legacy pending-bucket move).
 */
class ShrinkToReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Set up a deck whose entry is bound to a physical copy that lives in
     * the deck's auto-managed deck-location — i.e. a copy "owned by the deck."
     *
     * @return array{deck: Deck, entry: DeckEntry, copy: CollectionEntry, deckLocation: Location}
     */
    private function buildDeckWithOwnedCopy(string $deckName = 'Selesnya Tokens'): array
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => $deckName,
            'format'  => 'commander',
        ]);
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();

        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLocation->id,
        ]);

        $entry = DeckEntry::create([
            'deck_id'          => $deck->id,
            'scryfall_id'      => $card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        return compact('deck', 'entry', 'copy', 'deckLocation');
    }

    public function test_destroying_a_deck_entry_marks_owned_copy_for_review(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy();

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $copy->refresh();
        $this->assertNull($copy->location_id, 'no_location route — location cleared');
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason);
        $this->assertEquals($deck->id, $copy->source_deck_id);
        $this->assertEquals('Selesnya Tokens', $copy->source_deck_name_snapshot);
        $this->assertFalse($copy->source_deck_deleted);
    }

    public function test_destroying_a_deck_entry_leaves_copies_in_other_locations_alone(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy();

        // Move the copy to a regular drawer the user owns — this is no longer
        // "owned by the deck" so deleting the deck_entry shouldn't move it.
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);
        $copy->update(['location_id' => $drawer->id]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $copy->refresh();
        $this->assertEquals($drawer->id, $copy->location_id);
        $this->assertNull($copy->source_deck_id);
        $this->assertNull($copy->review_reason);
    }

    public function test_destroying_a_deck_entry_without_physical_copy_is_a_noop_for_collection(): void
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'No Copies',
            'format'  => 'commander',
        ]);
        $entry = DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        // Nothing should have been flagged for review.
        $this->assertSame(0, CollectionEntry::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('review_reason')
            ->count());
    }

    public function test_unlinking_physical_copy_via_update_marks_it_for_review(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy();

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => null,
            ])
            ->assertOk();

        $copy->refresh();
        $this->assertNull($copy->location_id);
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason);
        $this->assertEquals($deck->id, $copy->source_deck_id);
    }

    public function test_deleting_deck_marks_all_owned_copies_for_review(): void
    {
        ['deck' => $deck, 'copy' => $copy] = $this->buildDeckWithOwnedCopy('Doomed');

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}")
            ->assertNoContent();

        $copy->refresh();
        $this->assertNull($copy->location_id);
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason);
        $this->assertNull($copy->source_deck_id, 'FK should null after deck delete');
        $this->assertEquals('Doomed', $copy->source_deck_name_snapshot);
        $this->assertTrue($copy->source_deck_deleted);
    }

    public function test_deleting_deck_marks_existing_review_copies_as_deleted(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy('Source');

        // First shrink — copy goes to review with deleted=false.
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();
        $copy->refresh();
        $this->assertFalse($copy->source_deck_deleted);

        // Now delete the deck — the already-flagged copy flips to deleted=true.
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}")
            ->assertNoContent();

        $copy->refresh();
        $this->assertTrue($copy->source_deck_deleted);
        $this->assertEquals('Source', $copy->source_deck_name_snapshot);
    }

    public function test_collection_show_exposes_source_deck_payload(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy('Selesnya');

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $this->withHeaders($this->headers())
            ->getJson("/api/collection/{$copy->id}")
            ->assertOk()
            ->assertJsonPath('source_deck.deck_id', $deck->id)
            ->assertJsonPath('source_deck.deck_name', 'Selesnya')
            ->assertJsonPath('source_deck.deleted', false);
    }

    public function test_sidebar_payload_surfaces_review_only_when_non_empty(): void
    {
        // No review-flagged copies yet — payload field is null.
        $this->withHeaders($this->headers())
            ->getJson('/api/location-groups')
            ->assertOk()
            ->assertJsonPath('review', null);

        ['deck' => $deck, 'entry' => $entry] = $this->buildDeckWithOwnedCopy('Surface');
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/location-groups')
            ->assertOk();

        $this->assertNotNull($response->json('review'));
        $this->assertEquals(1, $response->json('review.card_count'));
    }

    public function test_user_reshelving_a_review_copy_clears_source_deck_stamp(): void
    {
        ['deck' => $deck, 'entry' => $entry, 'copy' => $copy] = $this->buildDeckWithOwnedCopy('Re-Shelf');

        // Push to review.
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();
        $copy->refresh();
        $this->assertEquals($deck->id, $copy->source_deck_id);

        // User picks a regular drawer.
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);
        $this->withHeaders($this->headers())
            ->patchJson("/api/collection/{$copy->id}", ['location_id' => $drawer->id])
            ->assertOk()
            ->assertJsonPath('source_deck', null);

        $copy->refresh();
        $this->assertEquals($drawer->id, $copy->location_id);
        $this->assertNull($copy->source_deck_id);
        $this->assertNull($copy->source_deck_name_snapshot);
        $this->assertNull($copy->review_reason, 'reshelving clears review_reason');
    }

    public function test_collection_update_rejects_setting_location_to_deck(): void
    {
        ['deck' => $deck] = $this->buildDeckWithOwnedCopy();
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();
        $entry = CollectionEntry::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/collection/{$entry->id}", [
                'location_id' => $deckLocation->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_id']);
    }
}
