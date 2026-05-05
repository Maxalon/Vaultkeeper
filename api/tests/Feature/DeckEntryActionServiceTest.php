<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\DeckEntryActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckEntryActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Deck $deck;
    private Location $deckLocation;
    private ScryfallCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
        ]);
        $this->deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $this->card = ScryfallCard::factory()->create();
    }

    private function service(): DeckEntryActionService
    {
        return app(DeckEntryActionService::class);
    }

    public function test_create_with_new_copy_makes_ce_in_deck_location_and_links_entry(): void
    {
        $entry = $this->service()->createWithNewCopy($this->deck, [
            'scryfall_id' => $this->card->scryfall_id,
            'zone'        => 'main',
            'quantity'    => 2,
        ]);

        $this->assertSame(2, (int) $entry->quantity);
        $this->assertSame('main', $entry->zone);
        $this->assertNotNull($entry->physical_copy_id);

        $copy = CollectionEntry::find($entry->physical_copy_id);
        $this->assertSame($this->deckLocation->id, $copy->location_id);
        $this->assertSame(2, (int) $copy->quantity);
        $this->assertFalse((bool) $copy->needs_review, 'user-created copy should not need review');
    }

    public function test_grow_with_new_copy_bumps_linked_ce(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
            'quantity'    => 2,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 2,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $result = $this->service()->growWithNewCopy($entry, 1);

        $this->assertSame(3, (int) $result->quantity);
        $this->assertSame(3, (int) $copy->fresh()->quantity);
        // Ensure the observer didn't accidentally mark this slot wanted —
        // the action service's skip flag should have prevented that.
        $this->assertNull($result->wanted);
    }

    public function test_grow_with_new_copy_creates_first_binding_for_unbound_slot(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $result = $this->service()->growWithNewCopy($entry, 2);

        $this->assertSame(3, (int) $result->quantity);
        $this->assertNotNull($result->physical_copy_id);
        $copy = CollectionEntry::find($result->physical_copy_id);
        $this->assertSame($this->deckLocation->id, $copy->location_id);
        $this->assertSame(2, (int) $copy->quantity, 'new CE covers the grown delta only');
    }

    public function test_shrink_and_discard_decrements_linked_ce_without_pending_queue(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
            'quantity'    => 4,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 4,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $result = $this->service()->shrinkAndDiscard($entry, 1);

        $this->assertSame(3, (int) $result->quantity);
        $this->assertSame(3, (int) $copy->fresh()->quantity);

        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
    }

    public function test_destroy_and_discard_deletes_entry_and_ce_without_pending(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $this->service()->destroyAndDiscard($entry);

        $this->assertNull(DeckEntry::find($entry->id));
        $this->assertNull(CollectionEntry::find($copy->id));
        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
    }

    public function test_skip_flag_is_one_shot(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        // First save with the flag set: observer skips its handlers.
        // We change zone (a benign field) so something is dirty.
        $entry->skipPendingQueueOnce = true;
        $entry->update(['zone' => 'side']);

        // Second save — flag is reset, default behaviour applies. Detach
        // the copy → observer should now move it to pending.
        $entry->update(['physical_copy_id' => null]);

        $copy->refresh();
        $pending = Location::where('user_id', $this->user->id)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->first();
        $this->assertNotNull($pending, 'second save without flag should have triggered the queue');
        $this->assertSame($pending->id, $copy->location_id);
    }
}
