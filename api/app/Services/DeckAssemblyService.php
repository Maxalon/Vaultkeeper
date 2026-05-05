<?php

namespace App\Services;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Centralizes the "this deck is physically assembled" path: create one
 * collection_entry per deck slot inside the deck's auto-managed location,
 * link each deck_entry's `physical_copy_id` to that CE.
 *
 * Assemble is **additive** (locked decision 3.2): slots that are already
 * bound (`physical_copy_id IS NOT NULL`) are left untouched. Re-running
 * assemble is a no-op for them — only NULL-bound slots get fresh CEs.
 *
 * Per-quantity excludes (locked 3.3) split a slot in two:
 *
 *   - the bound half (qty = N − excluded) gets a CE in the deck-location;
 *   - a sibling deck_entry with qty = excluded, `wanted = zone`, and
 *     `physical_copy_id = null` represents the missing copies.
 *
 * Commanders + signature spells are exempt from partial-exclude (the modal
 * hides the count picker for them); `excluded == quantity` fully un-binds
 * the slot, anything in between for those rows is rejected.
 *
 * Concurrency: per-user `Cache::lock('user:{id}:assembly', 30)` per locked
 * decision 3.5 — the second concurrent caller fails fast, doesn't queue.
 */
class DeckAssemblyService
{
    public function __construct(private ReviewQueueService $reviewQueue) {}

    /**
     * @param  AssembleIntent  $intent  Master toggle / per-section selection / per-quantity excludes
     * @return array{created_ces: int, slots_split: int, slots_marked_wanted: int}
     */
    public function assemble(Deck $deck, AssembleIntent $intent): array
    {
        return $this->withUserLock($deck->user_id, function () use ($deck, $intent) {
            return DB::transaction(function () use ($deck, $intent) {
                $deckLocation = $this->ensureDeckLocation($deck);

                $createdCes = 0;
                $slotsSplit = 0;
                $slotsMarkedWanted = 0;

                $entries = DeckEntry::query()
                    ->where('deck_id', $deck->id)
                    ->whereNull('physical_copy_id')
                    ->get();

                foreach ($entries as $entry) {
                    if (! $intent->coversSection($entry->zone)) {
                        continue;
                    }

                    $excluded = $intent->excludedFor($entry->scryfall_id, $entry->zone, (int) $entry->quantity);
                    $needed   = (int) $entry->quantity;
                    $toBind   = max(0, $needed - $excluded);

                    // Commanders & signature spells: count picker is hidden
                    // in the modal, so any partial exclude (0 < excluded
                    // < needed) is a contract violation. Reject so a
                    // malformed request doesn't half-assemble the slot.
                    if (($entry->is_commander || $entry->is_signature_spell)
                        && $excluded > 0 && $excluded < $needed) {
                        throw new RuntimeException(
                            "Partial-exclude not allowed for commander or signature spell entries (entry {$entry->id})."
                        );
                    }

                    if ($toBind === 0) {
                        // Fully excluded — leave the slot unbound and mark
                        // it wanted so the deckbuilder shows it.
                        $entry->skipPendingQueueOnce = true;
                        $entry->wanted = $entry->zone;
                        $entry->save();
                        $slotsMarkedWanted++;
                        continue;
                    }

                    if ($excluded === 0) {
                        // Whole slot becomes bound — single CE creation.
                        $ce = $this->createDeckLocationCopy($deck, $deckLocation, $entry, $toBind);
                        $entry->skipPendingQueueOnce = true;
                        $entry->physical_copy_id = $ce->id;
                        $entry->save();
                        $createdCes++;
                        continue;
                    }

                    // Partial exclude (locked 3.3): shrink THIS row and
                    // insert a sibling with `wanted = zone` for the gap.
                    $bindCe = $this->createDeckLocationCopy($deck, $deckLocation, $entry, $toBind);
                    $entry->skipPendingQueueOnce = true;
                    $entry->fill([
                        'quantity'         => $toBind,
                        'physical_copy_id' => $bindCe->id,
                        'wanted'           => null,
                    ])->save();

                    DeckEntry::create([
                        'deck_id'                => $deck->id,
                        'scryfall_id'            => $entry->scryfall_id,
                        'quantity'               => $excluded,
                        'zone'                   => $entry->zone,
                        'category'               => $entry->category,
                        'is_commander'           => false,
                        'is_signature_spell'     => false,
                        'signature_for_entry_id' => null,
                        'wanted'                 => $entry->zone,
                        'physical_copy_id'       => null,
                    ]);

                    $createdCes++;
                    $slotsSplit++;
                }

                $deckLocation->refreshSetCodes();

                return [
                    'created_ces'         => $createdCes,
                    'slots_split'         => $slotsSplit,
                    'slots_marked_wanted' => $slotsMarkedWanted,
                ];
            });
        });
    }

    /**
     * Tear down an assembled deck. Every CE in the deck-location is sent
     * to the review queue with reason `no_location` — uniform treatment
     * regardless of whether the user had edited the copy or not. *Where
     * a card came from doesn't matter, where it's going does*: the user
     * gets to decide on the /review surface. No CE is ever deleted by
     * unassemble. Every deck_entry's `physical_copy_id` is cleared.
     *
     * @return array{marked_for_review: int}
     */
    public function unassemble(Deck $deck): array
    {
        return $this->withUserLock($deck->user_id, function () use ($deck) {
            return DB::transaction(function () use ($deck) {
                $deckLocation = Location::query()
                    ->where('deck_id', $deck->id)
                    ->where('role', Location::ROLE_DECK)
                    ->first();

                $markedForReview = 0;

                if ($deckLocation !== null) {
                    $copies = CollectionEntry::query()
                        ->where('location_id', $deckLocation->id)
                        ->get();

                    foreach ($copies as $copy) {
                        $this->reviewQueue->markCopyForReview($copy, $deck);
                        $markedForReview++;
                    }
                }

                // Drop physical_copy_id on every entry of this deck. The
                // skip flag prevents the observer from re-queueing copies
                // we already handled above.
                $entries = DeckEntry::query()
                    ->where('deck_id', $deck->id)
                    ->whereNotNull('physical_copy_id')
                    ->get();
                foreach ($entries as $entry) {
                    $entry->skipPendingQueueOnce = true;
                    $entry->update(['physical_copy_id' => null]);
                }

                if ($deckLocation !== null) {
                    $deckLocation->refreshSetCodes();
                }

                return [
                    'marked_for_review' => $markedForReview,
                ];
            });
        });
    }

    /**
     * Run the body under a per-user assembly lock. Concurrent assemble or
     * unassemble calls on the same user are serialized; if the lock is
     * already held, the second caller fails fast (a 30s wait would just
     * frustrate the user).
     *
     * @template T
     * @param  callable(): T  $body
     * @return T
     */
    private function withUserLock(int $userId, callable $body): mixed
    {
        $lock = Cache::lock("user:{$userId}:assembly", 30);
        if (! $lock->get()) {
            throw new RuntimeException('Another assembly operation is in progress for this user.');
        }
        try {
            return $body();
        } finally {
            $lock->release();
        }
    }

    private function ensureDeckLocation(Deck $deck): Location
    {
        $deckLocation = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->first();
        if ($deckLocation !== null) {
            return $deckLocation;
        }
        // DeckObserver normally creates this on Deck::create. Belt-and-
        // braces fallback for backfill cases where the row is missing.
        return Location::create([
            'user_id' => $deck->user_id,
            'deck_id' => $deck->id,
            'role'    => Location::ROLE_DECK,
            'type'    => 'drawer',
            'name'    => 'Deck: '.mb_substr($deck->name, 0, 94),
        ]);
    }

    private function createDeckLocationCopy(Deck $deck, Location $deckLocation, DeckEntry $entry, int $quantity): CollectionEntry
    {
        return CollectionEntry::create([
            'user_id'       => $deck->user_id,
            'scryfall_id'   => $entry->scryfall_id,
            'location_id'   => $deckLocation->id,
            'quantity'      => $quantity,
            'condition'     => 'NM',
            'foil'          => false,
            'notes'         => null,
            'review_reason' => ReviewReason::DefaultValuesApplied,
        ]);
    }
}
