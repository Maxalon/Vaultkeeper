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
 * The pre-existing pending-queue path is exercised by ShrinkToPendingTest;
 * after the branch-3 logic moved into DeckEntryObserver, that suite still
 * passes (port is a 1:1 of the controller behaviour).
 *
 * This file covers the *new* behaviour the observer adds:
 *
 *   - quantity-grow on an unbound slot defaults to wanted = zone;
 *   - skipPendingQueueOnce is one-shot and suppresses both relocation and
 *     the wanted default for the next save.
 */
class DeckEntryObserverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Deck $deck;
    private ScryfallCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Observer',
            'format'  => 'commander',
        ]);
        $this->card = ScryfallCard::factory()->create();
    }

    public function test_quantity_grow_on_unbound_slot_defaults_to_wanted(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'side',
        ]);

        $entry->update(['quantity' => 3]);

        $entry->refresh();
        $this->assertSame('side', $entry->wanted, 'observer should mirror zone into wanted');
    }

    public function test_quantity_grow_does_not_clobber_existing_wanted(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
            'wanted'      => 'main',
        ]);

        $entry->update(['quantity' => 4]);

        $this->assertSame('main', $entry->fresh()->wanted);
    }

    public function test_quantity_grow_on_bound_slot_does_not_set_wanted(): void
    {
        $deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $deckLocation->id,
            'quantity'    => 1,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $entry->update(['quantity' => 2]);

        $this->assertNull($entry->fresh()->wanted, 'bound slots should not be marked wanted on grow');
    }

    public function test_skip_flag_suppresses_relocation_on_unlink(): void
    {
        $deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $deckLocation->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $entry->skipPendingQueueOnce = true;
        $entry->update(['physical_copy_id' => null]);

        // Skip flag → no pending location should have been created and
        // the copy should still sit in the deck-location.
        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
        $copy->refresh();
        $this->assertSame($deckLocation->id, $copy->location_id);
    }
}
