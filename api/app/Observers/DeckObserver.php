<?php

namespace App\Observers;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\Location;
use App\Services\PendingRelocationService;

class DeckObserver
{
    public function __construct(private PendingRelocationService $pendingRelocations) {}


    /**
     * Each deck gets its own auto-managed Location row used as the physical
     * "this deck owns these copies" bucket. The FK on locations.deck_id
     * cascades on deck delete, so we only need to handle create/rename here.
     */
    public function created(Deck $deck): void
    {
        Location::create([
            'user_id' => $deck->user_id,
            'deck_id' => $deck->id,
            'role'    => Location::ROLE_DECK,
            // `type` is enum('drawer','binder') at the schema level; role='deck'
            // already identifies this row as the deck-location, so we pick
            // 'drawer' arbitrarily for the type slot.
            'type'    => 'drawer',
            'name'    => $this->locationName($deck->name),
        ]);
    }

    public function updated(Deck $deck): void
    {
        if (! $deck->wasChanged('name')) {
            return;
        }

        Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->update(['name' => $this->locationName($deck->name)]);
    }

    /**
     * Before a deck is deleted, sweep its physical copies into the user's
     * pending bucket so they aren't lost (the deck-location FK cascades, and
     * collection_entries.location_id is nullOnDelete — if we did nothing,
     * those copies would silently land in "Unassigned"). Also flips
     * `source_deck_deleted` on copies already in pending that came from
     * this deck, since `source_deck_id` is about to null out and the
     * snapshot is the only label left.
     */
    public function deleting(Deck $deck): void
    {
        $deckLocationId = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->value('id');

        if ($deckLocationId !== null) {
            $copies = CollectionEntry::query()
                ->where('location_id', $deckLocationId)
                ->get();

            foreach ($copies as $copy) {
                $this->pendingRelocations->moveCopyToPending($copy, $deck, deckBeingDeleted: true);
            }
        }

        // Pre-existing pending copies sourced from this deck — keep their
        // snapshot, mark deleted=true. Done in bulk; no model events needed.
        CollectionEntry::query()
            ->where('source_deck_id', $deck->id)
            ->update(['source_deck_deleted' => true]);
    }

    private function locationName(string $deckName): string
    {
        // Truncate so we never overflow locations.name (varchar 100). Match
        // the "Deck: " prefix the docblock on migration #2 documents.
        $prefix = 'Deck: ';
        $room = 100 - strlen($prefix);

        return $prefix . mb_substr($deckName, 0, $room);
    }
}
