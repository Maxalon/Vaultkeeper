<?php

namespace App\Observers;

use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Services\ReviewQueueService;

/**
 * Centralizes deck-entry side effects that branch 3 had inlined inside
 * DeckEntryController:
 *
 *   - on update: if the user unlinked or swapped the entry's bound copy,
 *     and the OLD copy lives in this deck's deck-location, send it to the
 *     review queue with reason `no_location`.
 *   - on delete: same, for the entry's currently-bound copy.
 *
 * Adds two pieces of behaviour the assembly stack needs:
 *
 *   - `$entry->skipPendingQueueOnce` — a one-shot flag honoured here. When
 *     set, the observer skips its review-queueing logic for that single
 *     save (and resets the flag). DeckEntryActionService uses this to
 *     execute "sold/discarded" intents — the user explicitly told us where
 *     the copy went, no need to queue it.
 *   - quantity-grow → `wanted = zone` default. When the user bumps an
 *     unbound slot's quantity (via the raw PATCH endpoint, not an inline
 *     picker), mark the slot as wanted so the deckbuilder shows the right
 *     fulfillment state. The action-service path bypasses this via the
 *     skip flag when the user explicitly took a different action.
 */
class DeckEntryObserver
{
    public function __construct(private ReviewQueueService $reviewQueue) {}

    public function updating(DeckEntry $entry): void
    {
        if ($this->consumeSkipFlag($entry)) {
            return;
        }

        if ($entry->isDirty('physical_copy_id')) {
            $previousCopyId = $entry->getOriginal('physical_copy_id');
            if ($previousCopyId !== null && $previousCopyId !== $entry->physical_copy_id) {
                $this->relocateIfInDeckLocation((int) $previousCopyId, $entry);
            }
        }

        // Default behaviour for an unbound slot whose quantity grows: mark
        // the slot wanted so the deckbuilder's fulfillment counter ticks
        // up. Inline-picker flows go through DeckEntryActionService and
        // set the skip flag instead, so they never reach here.
        if ($entry->isDirty('quantity')) {
            $oldQty = (int) ($entry->getOriginal('quantity') ?? 0);
            $newQty = (int) $entry->quantity;
            if ($newQty > $oldQty
                && $entry->physical_copy_id === null
                && $entry->wanted === null) {
                $entry->wanted = $entry->zone;
            }
        }
    }

    public function deleting(DeckEntry $entry): void
    {
        if ($this->consumeSkipFlag($entry)) {
            return;
        }

        if ($entry->physical_copy_id !== null) {
            $this->relocateIfInDeckLocation((int) $entry->physical_copy_id, $entry);
        }
    }

    /**
     * Read-and-clear the one-shot skip flag. Returning true tells the
     * caller to bail out of the rest of the handler.
     */
    private function consumeSkipFlag(DeckEntry $entry): bool
    {
        if (($entry->skipPendingQueueOnce ?? false) === true) {
            $entry->skipPendingQueueOnce = false;
            return true;
        }
        return false;
    }

    /**
     * Mark `$copyId` for review with reason `no_location` — but only if
     * that copy currently lives in this deck's deck-location. Copies the
     * user has shelved elsewhere (binders, drawers, even a different deck)
     * are left alone; the user already chose where they belong.
     */
    private function relocateIfInDeckLocation(int $copyId, DeckEntry $entry): void
    {
        $copy = CollectionEntry::find($copyId);
        if ($copy === null) {
            return;
        }

        $deck = $entry->deck;
        if ($deck === null || $copy->user_id !== $deck->user_id) {
            return;
        }

        $deckLocationId = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->value('id');

        if ($deckLocationId === null || $copy->location_id !== $deckLocationId) {
            return;
        }

        $this->reviewQueue->markCopyForReview($copy, $deck);
    }
}
