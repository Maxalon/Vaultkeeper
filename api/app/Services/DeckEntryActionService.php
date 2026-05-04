<?php

namespace App\Services;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Backs the inline pickers on DeckView (locked decision 11). Every method
 * here represents an explicit "the user chose this outcome" intent that
 * differs from the default behaviour DeckEntryObserver applies — and so
 * each one sets the `skipPendingQueueOnce` flag on the entry to suppress
 * that default for the same save.
 *
 * The four operations:
 *
 *   - createWithNewCopy   : "I just bought it" while adding a brand-new slot.
 *   - growWithNewCopy     : "I just bought it" while bumping a slot's qty.
 *   - shrinkAndDiscard    : "Sold or discarded" while dropping a slot's qty.
 *   - destroyAndDiscard   : "Sold or discarded" while removing a slot.
 */
class DeckEntryActionService
{
    /**
     * Add a deck_entry for a card the user just acquired and is also
     * tucking into this deck. Creates a CE in the deck-location with
     * `needs_review = false` (the user explicitly confirmed it) and links
     * the new deck_entry to it.
     *
     * @param  array{scryfall_id: string, zone?: string, quantity?: int, category?: ?string}  $payload
     */
    public function createWithNewCopy(Deck $deck, array $payload): DeckEntry
    {
        $deckLocation = $this->requireDeckLocation($deck);

        return DB::transaction(function () use ($deck, $payload, $deckLocation) {
            $quantity = max(1, (int) ($payload['quantity'] ?? 1));
            $zone     = $payload['zone'] ?? 'main';

            $copy = CollectionEntry::create([
                'user_id'      => $deck->user_id,
                'scryfall_id'  => $payload['scryfall_id'],
                'location_id'  => $deckLocation->id,
                'quantity'     => $quantity,
                'condition'    => 'NM',
                'foil'         => false,
                'needs_review' => false,
            ]);

            $entry = DeckEntry::create([
                'deck_id'          => $deck->id,
                'scryfall_id'      => $payload['scryfall_id'],
                'quantity'         => $quantity,
                'zone'             => $zone,
                'category'         => $payload['category'] ?? null,
                'physical_copy_id' => $copy->id,
            ]);

            $deckLocation->refreshSetCodes();

            return $entry;
        });
    }

    /**
     * Bind an unbound deck-entry to a fresh CE in the deck-location with
     * the entry's current quantity. Equivalent to "I just bought this
     * card and I'm putting it in this deck" for an existing wishlist
     * slot. Sets the skip flag so the observer doesn't replay any
     * default behaviour during the bind.
     */
    public function bindAsNewCopy(DeckEntry $entry): DeckEntry
    {
        if ($entry->physical_copy_id !== null) {
            throw new RuntimeException("Entry {$entry->id} is already bound to a physical copy.");
        }

        return DB::transaction(function () use ($entry) {
            $deck = $entry->deck;
            $deckLocation = $this->requireDeckLocation($deck);
            $copy = CollectionEntry::create([
                'user_id'      => $deck->user_id,
                'scryfall_id'  => $entry->scryfall_id,
                'location_id'  => $deckLocation->id,
                'quantity'     => max(1, (int) $entry->quantity),
                'condition'    => 'NM',
                'foil'         => false,
                'needs_review' => false,
            ]);

            $entry->skipPendingQueueOnce = true;
            $entry->fill([
                'physical_copy_id' => $copy->id,
                'wanted'           => null,
            ])->save();

            $deckLocation->refreshSetCodes();

            return $entry->fresh();
        });
    }

    /**
     * Bump an existing slot's quantity by `$delta`, keeping the linked CE
     * in step. The skip flag suppresses the observer's default behaviour
     * (which would mark the slot wanted on grow when no copy is bound).
     */
    public function growWithNewCopy(DeckEntry $entry, int $delta): DeckEntry
    {
        if ($delta <= 0) {
            throw new RuntimeException('growWithNewCopy delta must be positive.');
        }

        return DB::transaction(function () use ($entry, $delta) {
            if ($entry->physical_copy_id === null) {
                // Nothing bound yet — create a deck-location CE for the
                // grown delta and bind to it. Equivalent to a one-shot
                // assemble for this slot.
                $deck = $entry->deck;
                $deckLocation = $this->requireDeckLocation($deck);
                $copy = CollectionEntry::create([
                    'user_id'      => $deck->user_id,
                    'scryfall_id'  => $entry->scryfall_id,
                    'location_id'  => $deckLocation->id,
                    'quantity'     => $delta,
                    'condition'    => 'NM',
                    'foil'         => false,
                    'needs_review' => false,
                ]);
                $entry->skipPendingQueueOnce = true;
                $entry->fill([
                    'quantity'         => (int) $entry->quantity + $delta,
                    'physical_copy_id' => $copy->id,
                    'wanted'           => null,
                ])->save();
                $deckLocation->refreshSetCodes();
                return $entry->fresh();
            }

            $copy = CollectionEntry::find($entry->physical_copy_id);
            if ($copy !== null) {
                $copy->update(['quantity' => (int) $copy->quantity + $delta]);
            }

            $entry->skipPendingQueueOnce = true;
            $entry->update(['quantity' => (int) $entry->quantity + $delta]);

            return $entry->fresh();
        });
    }

    /**
     * Drop a slot's quantity by `$delta`, releasing the freed copies
     * (i.e. they're sold/discarded — NOT queued to pending). If the
     * linked CE drops to zero, it's deleted; otherwise its quantity is
     * reduced in step.
     */
    public function shrinkAndDiscard(DeckEntry $entry, int $delta): DeckEntry
    {
        if ($delta <= 0) {
            throw new RuntimeException('shrinkAndDiscard delta must be positive.');
        }
        if ($delta >= (int) $entry->quantity) {
            // Edge case: requested shrink eats the whole slot — that's
            // really destroy. Funnel through the dedicated method.
            $this->destroyAndDiscard($entry);
            return $entry;
        }

        return DB::transaction(function () use ($entry, $delta) {
            if ($entry->physical_copy_id !== null) {
                $copy = CollectionEntry::find($entry->physical_copy_id);
                if ($copy !== null) {
                    $newQty = (int) $copy->quantity - $delta;
                    if ($newQty <= 0) {
                        $copy->delete();
                        $entry->physical_copy_id = null;
                    } else {
                        $copy->update(['quantity' => $newQty]);
                    }
                }
            }

            $entry->skipPendingQueueOnce = true;
            $entry->update(['quantity' => (int) $entry->quantity - $delta]);

            return $entry->fresh();
        });
    }

    /**
     * Remove a slot whose copy was sold/discarded. The linked CE (if any)
     * is deleted outright instead of being moved to pending — the user
     * already declared its fate.
     */
    public function destroyAndDiscard(DeckEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            if ($entry->physical_copy_id !== null) {
                CollectionEntry::where('id', $entry->physical_copy_id)->delete();
            }
            $entry->skipPendingQueueOnce = true;
            $entry->delete();
        });
    }

    private function requireDeckLocation(Deck $deck): Location
    {
        $loc = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->first();
        if ($loc === null) {
            throw new RuntimeException("Deck {$deck->id} has no deck-location.");
        }
        return $loc;
    }
}
