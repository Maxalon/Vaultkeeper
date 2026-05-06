<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the PhysicalCopyDropdown bind path — the SPA submits a PATCH
 * setting `physical_copy_id` on a deck_entry, and the controller is now
 * responsible for moving the picked CE into the deck-location and
 * splitting it if the source has more copies than the slot needs.
 */
class PhysicalCopyDropdownBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Deck $deck;
    private Location $deckLocation;
    private Location $binder;
    private ScryfallCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Bind Test',
            'format'  => 'commander',
        ]);
        $this->deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $this->binder = Location::factory()->create(['user_id' => $this->user->id, 'name' => 'Binder']);
        $this->card = ScryfallCard::factory()->create();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_binding_moves_whole_ce_when_qty_matches_slot(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->binder->id,
            'quantity'    => 1,
        ]);
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => $copy->id,
            ])
            ->assertOk();

        $entry->refresh();
        $this->assertSame($copy->id, $entry->physical_copy_id);
        $copy->refresh();
        $this->assertSame($this->deckLocation->id, $copy->location_id);
        $this->assertSame(1, (int) $copy->quantity);
    }

    public function test_binding_splits_ce_when_source_has_extra_copies(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->binder->id,
            'quantity'    => 4,
        ]);
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => $copy->id,
            ])
            ->assertOk();

        // Source CE shrinks to 3 in the binder; a fresh CE with qty=1
        // appears in the deck-location and is what the entry now points
        // at.
        $copy->refresh();
        $this->assertSame($this->binder->id, $copy->location_id);
        $this->assertSame(3, (int) $copy->quantity);

        $entry->refresh();
        $this->assertNotSame($copy->id, $entry->physical_copy_id);

        $boundCe = CollectionEntry::find($entry->physical_copy_id);
        $this->assertSame($this->deckLocation->id, $boundCe->location_id);
        $this->assertSame(1, (int) $boundCe->quantity);
        $this->assertSame($this->card->scryfall_id, $boundCe->scryfall_id);
    }

    public function test_binding_rejects_copy_with_insufficient_quantity(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->binder->id,
            'quantity'    => 1,
        ]);
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 4,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => $copy->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['physical_copy_id']);

        $entry->refresh();
        $this->assertNull($entry->physical_copy_id);
    }

    public function test_binding_rejects_copy_already_in_another_decks_location(): void
    {
        $otherDeck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Other Deck',
            'format'  => 'commander',
        ]);
        $otherDeckLocation = Location::where('deck_id', $otherDeck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $otherDeckLocation->id,
            'quantity'    => 1,
        ]);
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => $copy->id,
            ])
            ->assertStatus(422);

        $copy->refresh();
        $this->assertSame($otherDeckLocation->id, $copy->location_id, 'cross-deck pick must not move the copy');
    }

    public function test_swapping_bound_copy_pushes_old_copy_to_pending(): void
    {
        // Slot is currently bound to a CE in this deck's deck-location.
        $oldCopy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
            'quantity'    => 1,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $oldCopy->id,
        ]);

        // User picks a different copy from a binder.
        $newCopy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->binder->id,
            'quantity'    => 1,
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'physical_copy_id' => $newCopy->id,
            ])
            ->assertOk();

        $newCopy->refresh();
        $this->assertSame($this->deckLocation->id, $newCopy->location_id);
        $entry->refresh();
        $this->assertSame($newCopy->id, $entry->physical_copy_id);

        // Old copy was kicked out of the deck-location → flagged for review.
        $oldCopy->refresh();
        $this->assertNull($oldCopy->location_id);
        $this->assertSame(\App\Enums\ReviewReason::NoLocation, $oldCopy->review_reason);
        $this->assertSame($this->deck->id, $oldCopy->source_deck_id);
    }
}
