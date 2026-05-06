<?php

namespace App\Services;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;

/**
 * Marks copies for the review queue when a deck operation orphans them.
 * Replaces the legacy PendingRelocationService bucket: instead of moving
 * the CE into a singleton "pending" Location, we null out its location_id
 * and stamp `review_reason = 'no_location'`. The /review surface picks
 * them up by reason rather than by Location.
 */
class ReviewQueueService
{
    /**
     * Send the given collection_entry to the review queue with reason
     * `no_location`, stamping the source deck so the review UI can
     * label "from <deck>". When `$deckBeingDeleted` is true (Deck
     * deleting observer), also flips `source_deck_deleted` since
     * the FK is about to null out and the snapshot becomes the only
     * label left.
     */
    public function markCopyForReview(
        CollectionEntry $copy,
        Deck $sourceDeck,
        bool $deckBeingDeleted = false,
    ): void {
        $copy->forceFill([
            'location_id'               => null,
            'review_reason'             => ReviewReason::NoLocation,
            'source_deck_id'            => $sourceDeck->id,
            'source_deck_name_snapshot' => mb_substr($sourceDeck->name, 0, 100),
            'source_deck_deleted'       => $deckBeingDeleted,
        ])->save();
    }
}
