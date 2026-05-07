<?php

namespace App\Observers;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\Location;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ReviewQueueService;

class DeckObserver
{
    public function __construct(private ReviewQueueService $reviewQueue) {}


    /**
     * Each deck gets its own auto-managed Location row used as the physical
     * "this deck owns these copies" bucket. The FK on locations.deck_id
     * cascades on deck delete, so we only need to handle create/rename here.
     */
    public function created(Deck $deck): void
    {
        Location::create([
            'user_id'    => $deck->user_id,
            'deck_id'    => $deck->id,
            'role'       => Location::ROLE_DECK,
            // `type` is enum('drawer','binder') at the schema level; role='deck'
            // already identifies this row as the deck-location, so we pick
            // 'drawer' arbitrarily for the type slot.
            'type'       => 'drawer',
            'name'       => $this->locationName($deck->name),
            // Drop the deck at the end of the top-level sidebar order. Importers
            // and other callers that want a specific group/position can rewrite
            // these fields after the observer fires.
            'group_id'   => null,
            'sort_order' => Location::nextTopLevelSortOrder($deck->user_id),
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
     * Before a deck is deleted, sweep its physical copies into the review
     * queue so they aren't lost (the deck-location FK cascades, and
     * collection_entries.location_id is nullOnDelete — if we did nothing,
     * those copies would silently land in "Unassigned"). Also flips
     * `source_deck_deleted` on copies already queued for review that came
     * from this deck, since `source_deck_id` is about to null out and the
     * snapshot is the only label left.
     */
    public function deleting(Deck $deck): void
    {
        $deckLocationId = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->value('id');

        $copiesMarked = 0;

        if ($deckLocationId !== null) {
            $copies = CollectionEntry::query()
                ->where('location_id', $deckLocationId)
                ->get();

            foreach ($copies as $copy) {
                $this->reviewQueue->markCopyForReview($copy, $deck, deckBeingDeleted: true);
                $copiesMarked++;
            }
        }

        // Pre-existing review-queued copies sourced from this deck — keep
        // their snapshot, mark deleted=true. Done in bulk; no model events
        // needed.
        CollectionEntry::query()
            ->where('source_deck_id', $deck->id)
            ->update(['source_deck_deleted' => true]);

        // Notify the deck owner if any physical copies were moved to the
        // review queue as a result of the deletion.
        if ($copiesMarked > 0) {
            $owner = User::find($deck->user_id);
            if ($owner) {
                app(NotificationService::class)->notify(
                    user:    $owner,
                    type:    'deck.card_marked_for_review',
                    payload: [
                        'deck_name'     => $deck->name,
                        'copies_count'  => $copiesMarked,
                    ],
                );
            }
        }
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
