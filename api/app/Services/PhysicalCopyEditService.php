<?php

namespace App\Services;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backs the Physical Copies tab's Edit modal: lets the user adjust the
 * condition / foil / notes / printing of the CE bound to a deck slot,
 * and split a stacked CE if the change should only apply to some copies.
 *
 * For in-place edits the source CE is patched directly and any
 * `default_values_applied` review flag is cleared (the user has now
 * explicitly confirmed the values). For split edits the source row keeps
 * its quantity reduced by the split count and a fresh CE + sibling
 * deck_entry are minted with the new attributes.
 */
class PhysicalCopyEditService
{
    /**
     * @param  array{
     *     apply_to: int,
     *     version?: int|null,
     *     condition?: string,
     *     foil?: bool,
     *     is_etched?: bool,
     *     notes?: ?string,
     *     scryfall_id?: string,
     * }  $payload
     */
    public function edit(Deck $deck, DeckEntry $entry, array $payload): DeckEntry
    {
        if ($entry->physical_copy_id === null) {
            throw ValidationException::withMessages([
                'entry' => ['Cannot edit a slot that has no bound physical copy.'],
            ]);
        }

        $applyTo = (int) $payload['apply_to'];
        if ($applyTo < 1 || $applyTo > (int) $entry->quantity) {
            throw ValidationException::withMessages([
                'apply_to' => ["apply_to must be between 1 and {$entry->quantity}."],
            ]);
        }

        $deckLocation = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->first();
        if ($deckLocation === null) {
            throw ValidationException::withMessages([
                'deck' => ['Deck location not found.'],
            ]);
        }

        $patchKeys = ['condition', 'foil', 'is_etched', 'notes', 'scryfall_id'];
        $patch = array_intersect_key($payload, array_flip($patchKeys));

        // Etched is mutually exclusive with foil — a true is_etched
        // forces foil=false on the resulting row regardless of what the
        // caller sent for the other field.
        if (array_key_exists('is_etched', $patch) && $patch['is_etched']) {
            $patch['foil'] = false;
        }

        if (array_key_exists('scryfall_id', $patch) && $patch['scryfall_id'] !== $entry->scryfall_id) {
            $newOracle = ScryfallCard::where('scryfall_id', $patch['scryfall_id'])->value('oracle_id');
            $curOracle = ScryfallCard::where('scryfall_id', $entry->scryfall_id)->value('oracle_id');
            if ($newOracle === null || $curOracle === null || $newOracle !== $curOracle) {
                throw ValidationException::withMessages([
                    'scryfall_id' => ['The selected printing must be of the same card.'],
                ]);
            }
        }

        return DB::transaction(function () use ($entry, $patch, $applyTo, $deckLocation, $payload) {
            $copy = CollectionEntry::query()
                ->where('id', $entry->physical_copy_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('version', $payload) && $payload['version'] !== null) {
                $copy->assertVersion((int) $payload['version']);
            }

            $newScryfallId = array_key_exists('scryfall_id', $patch) ? $patch['scryfall_id'] : null;
            $printingChanged = $newScryfallId !== null && $newScryfallId !== $entry->scryfall_id;

            if ($applyTo === (int) $entry->quantity) {
                $update = [];
                if (array_key_exists('condition', $patch)) $update['condition'] = $patch['condition'];
                if (array_key_exists('foil', $patch))      $update['foil']      = (bool) $patch['foil'];
                if (array_key_exists('is_etched', $patch)) $update['is_etched'] = (bool) $patch['is_etched'];
                if (array_key_exists('notes', $patch))     $update['notes']     = $patch['notes'];
                if ($printingChanged)                      $update['scryfall_id'] = $newScryfallId;
                // The user has explicitly confirmed the values — drop any
                // default-values-applied flag so the copy stops nagging
                // the review queue.
                if ($copy->review_reason !== null) {
                    $update['review_reason'] = null;
                }
                if (! empty($update)) {
                    $copy->update($update);
                }

                if ($printingChanged) {
                    $entry->skipPendingQueueOnce = true;
                    $entry->update(['scryfall_id' => $newScryfallId]);
                }

                $deckLocation->refreshSetCodes();
                return $entry->fresh();
            }

            // Split path: source CE keeps (qty - applyTo) at its current
            // attrs; mint a new CE + sibling deck_entry for the applyTo
            // portion with the new attrs layered on top.
            $newCopy = CollectionEntry::create([
                'user_id'       => $copy->user_id,
                'scryfall_id'   => $newScryfallId ?? $copy->scryfall_id,
                'location_id'   => $deckLocation->id,
                'quantity'      => $applyTo,
                'condition'     => $patch['condition'] ?? $copy->condition,
                'foil'          => array_key_exists('foil', $patch) ? (bool) $patch['foil'] : (bool) $copy->foil,
                'is_etched'     => array_key_exists('is_etched', $patch) ? (bool) $patch['is_etched'] : (bool) $copy->is_etched,
                'notes'         => array_key_exists('notes', $patch) ? $patch['notes'] : $copy->notes,
                'review_reason' => null,
            ]);

            $copy->update(['quantity' => (int) $copy->quantity - $applyTo]);

            $entry->skipPendingQueueOnce = true;
            $entry->update(['quantity' => (int) $entry->quantity - $applyTo]);

            $sibling = new DeckEntry([
                'deck_id'                => $entry->deck_id,
                'scryfall_id'            => $newScryfallId ?? $entry->scryfall_id,
                'quantity'               => $applyTo,
                'zone'                   => $entry->zone,
                'category'               => $entry->category,
                'is_commander'           => false,
                'is_signature_spell'     => false,
                'signature_for_entry_id' => null,
                'wanted'                 => null,
                'physical_copy_id'       => $newCopy->id,
            ]);
            $sibling->skipPendingQueueOnce = true;
            $sibling->save();

            $deckLocation->refreshSetCodes();
            return $sibling->fresh();
        });
    }
}
