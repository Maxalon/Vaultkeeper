<?php

namespace App\Http\Controllers;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Backs the global /review route + the per-deck Review tab on
 * DeckView. Surfaces every CE the user has with `review_reason IS NOT
 * NULL`, with the source-deck label resolved (live name when the deck
 * still exists, snapshot name when it doesn't), and lets the user
 * resolve them in bulk.
 *
 * Three reason categories today:
 *
 *   - 'no_location'             — CE has no location_id; user picks one.
 *   - 'default_values_applied'  — assemble minted with default values;
 *                                 user can accept or correct.
 *   - 'card_data_changed'       — Scryfall deleted/migrated the card.
 *
 * The actual write-side logic — flagging copies — lives in
 * ReviewQueueService, BulkSyncService, and DeckAssemblyService. This
 * controller is purely read + resolve.
 */
class ReviewController extends Controller
{
    /**
     * GET /api/review[?deck_id=N][&reason=...]
     *
     * Returns every review-flagged CE, optionally filtered by source
     * deck and/or reason. The per-deck filter matches `source_deck_id`
     * (live FK), not the snapshot — once a deck is deleted its copies
     * are global review until the user re-shelves them.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $request->validate([
            'deck_id' => 'sometimes|integer',
            'reason'  => 'sometimes|string|in:no_location,default_values_applied,card_data_changed',
        ]);

        $query = CollectionEntry::query()
            ->where('user_id', $userId)
            ->whereNotNull('review_reason')
            ->with(['card:scryfall_id,name,set_code,collector_number,image_small,image_normal,is_dfc',
                'location:id,name']);

        if ($request->filled('deck_id')) {
            $query->where('source_deck_id', (int) $request->query('deck_id'));
        }

        if ($request->filled('reason')) {
            $query->where('review_reason', $request->query('reason'));
        }

        $rows = $query->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn (CollectionEntry $e) => $this->present($e))->values(),
        ]);
    }

    /**
     * GET /api/review/count
     *
     * Compact endpoint for nav badging. Returns 0 when nothing is in
     * the queue; never 404s.
     */
    public function count(): JsonResponse
    {
        $userId = auth()->id();
        $count  = CollectionEntry::query()
            ->where('user_id', $userId)
            ->whereNotNull('review_reason')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/review/resolve
     *
     * Body shape:
     *   {
     *     "assignments": [
     *       { "collection_entry_id": 12, "target_location_id": 5 },
     *       { "collection_entry_id": 13, "discard": true },
     *       { "collection_entry_id": 14, "accept_defaults": true },
     *       { "collection_entry_id": 15 }   // skipped
     *     ]
     *   }
     *
     * Per-row outcome:
     *   - target_location_id set → move CE there, clear review_reason +
     *     source-deck stamp, merge into a matching CE if one already
     *     exists at the destination.
     *   - discard=true → delete the CE outright.
     *   - accept_defaults=true → only valid for review_reason =
     *     'default_values_applied'. Clears review_reason without moving
     *     the row. Returns 422 for other reasons.
     *   - none of the above → skip.
     *
     * @return JsonResponse  { resolved, merged, discarded, accepted, skipped }
     */
    public function resolve(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $data = $request->validate([
            'assignments'                        => 'required|array|min:1',
            'assignments.*.collection_entry_id'  => 'required|integer',
            'assignments.*.target_location_id'   => 'sometimes|nullable|integer',
            'assignments.*.discard'              => 'sometimes|boolean',
            'assignments.*.accept_defaults'      => 'sometimes|boolean',
        ]);

        $resolved  = 0;
        $merged    = 0;
        $discarded = 0;
        $accepted  = 0;
        $skipped   = 0;

        // Touched locations need a set_codes refresh after the move so the
        // sidebar chips drop / pick up codes. Collected here, run outside
        // the transaction once the writes are visible.
        $touchedLocationIds = [];

        DB::transaction(function () use (
            $data, $userId, &$resolved, &$merged, &$discarded, &$accepted, &$skipped, &$touchedLocationIds,
        ) {
            foreach ($data['assignments'] as $row) {
                $copy = CollectionEntry::query()
                    ->where('id', $row['collection_entry_id'])
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if ($copy === null) {
                    $skipped++;
                    continue;
                }

                $sourceLocId    = $copy->location_id;
                $discard        = (bool) ($row['discard'] ?? false);
                $acceptDefaults = (bool) ($row['accept_defaults'] ?? false);
                $target         = array_key_exists('target_location_id', $row)
                    ? $row['target_location_id']
                    : null;

                if ($discard) {
                    $copy->delete();
                    $discarded++;
                    if ($sourceLocId !== null) $touchedLocationIds[$sourceLocId] = true;
                    continue;
                }

                if ($acceptDefaults) {
                    if ($copy->review_reason !== ReviewReason::DefaultValuesApplied) {
                        // accept_defaults only makes sense for the
                        // assemble-defaults case. Reject loud — caller
                        // bug, not a silent skip.
                        abort(422, 'accept_defaults is only valid for default_values_applied rows.');
                    }
                    $copy->forceFill([
                        'review_reason'             => null,
                        'source_deck_id'            => null,
                        'source_deck_name_snapshot' => null,
                        'source_deck_deleted'       => false,
                    ])->save();
                    $accepted++;
                    continue;
                }

                if ($target === null) {
                    // No-op skip — SPA may have left this row unselected
                    // on purpose so the user can come back to it.
                    $skipped++;
                    continue;
                }

                // Must be a user-managed location belonging to the caller.
                // Auto-managed rows (deck) are off-limits as targets.
                $targetLoc = Location::query()
                    ->where('id', $target)
                    ->where('user_id', $userId)
                    ->where('role', Location::ROLE_USER)
                    ->first();
                if ($targetLoc === null) {
                    $skipped++;
                    continue;
                }

                // Merge: if the destination already has a CE with the same
                // printing + condition + foil, sum quantities and delete
                // the review-flagged row.
                $match = CollectionEntry::query()
                    ->where('user_id', $userId)
                    ->where('location_id', $targetLoc->id)
                    ->where('scryfall_id', $copy->scryfall_id)
                    ->where('condition', $copy->condition)
                    ->where('foil', (bool) $copy->foil)
                    ->where('id', '!=', $copy->id)
                    ->lockForUpdate()
                    ->first();

                if ($match !== null) {
                    $match->update(['quantity' => (int) $match->quantity + (int) $copy->quantity]);
                    $copy->delete();
                    $merged++;
                } else {
                    $copy->forceFill([
                        'location_id'               => $targetLoc->id,
                        'review_reason'             => null,
                        'source_deck_id'            => null,
                        'source_deck_name_snapshot' => null,
                        'source_deck_deleted'       => false,
                    ])->save();
                    $resolved++;
                }
                if ($sourceLocId !== null) $touchedLocationIds[$sourceLocId] = true;
                $touchedLocationIds[$targetLoc->id] = true;
            }
        });

        foreach (array_keys($touchedLocationIds) as $locId) {
            Location::find($locId)?->refreshSetCodes();
        }

        return response()->json([
            'resolved'  => $resolved,
            'merged'    => $merged,
            'discarded' => $discarded,
            'accepted'  => $accepted,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Presenter — flat shape the SPA renders directly. The `source_deck`
     * block prefers the live Deck record (so renames track), falls back
     * to the snapshot once the deck is gone.
     *
     * @return array<string, mixed>
     */
    private function present(CollectionEntry $entry): array
    {
        $card = $entry->card;
        $sourceDeck = ($entry->source_deck_id !== null || $entry->source_deck_name_snapshot !== null)
            ? [
                'deck_id'   => $entry->source_deck_id,
                'deck_name' => $entry->source_deck_name_snapshot ?? '',
                'deleted'   => (bool) $entry->source_deck_deleted,
            ]
            : null;

        return [
            'id'             => $entry->id,
            'quantity'       => (int) $entry->quantity,
            'condition'      => $entry->condition,
            'foil'           => (bool) $entry->foil,
            'notes'          => $entry->notes,
            'review_reason'  => $entry->review_reason?->value,
            'location_id'    => $entry->location_id,
            'location_name'  => $entry->location?->name,
            'source_deck'    => $sourceDeck,
            'card'           => $card ? [
                'scryfall_id'      => $card->scryfall_id,
                'name'             => $card->name,
                'set_code'         => $card->set_code,
                'collector_number' => $card->collector_number,
                'image_small'      => $card->image_small,
                'image_normal'     => $card->image_normal,
                'is_dfc'           => (bool) $card->is_dfc,
            ] : null,
        ];
    }
}
