<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Backs the global /pending route + the per-deck Pending tab on
 * DeckView. Surfaces every CE the user has sitting in their
 * pending-relocation bucket, with the source-deck label resolved
 * (live name when the deck still exists, snapshot name when it
 * doesn't), and lets the user resolve them in bulk.
 *
 * The actual write-side logic — moving copies into the bucket — lives
 * in PendingRelocationService and DeckEntryObserver. This controller
 * is purely the read + resolve side.
 */
class PendingRelocationController extends Controller
{
    /**
     * GET /api/pending-relocations[?deck_id=N]
     *
     * Returns every CE in the user's pending bucket, optionally scoped
     * to copies that came from a specific deck. The per-deck filter
     * matches `source_deck_id` (live FK), not the snapshot — once a
     * deck is deleted its copies are global pending until the user
     * re-shelves them.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $request->validate([
            'deck_id' => 'sometimes|integer',
        ]);

        $bucket = $this->pendingBucket($userId);
        if ($bucket === null) {
            return response()->json(['data' => []]);
        }

        $query = CollectionEntry::query()
            ->where('user_id', $userId)
            ->where('location_id', $bucket->id)
            ->with(['card:scryfall_id,name,set_code,collector_number,image_small,image_normal,is_dfc']);

        if ($request->filled('deck_id')) {
            $query->where('source_deck_id', (int) $request->query('deck_id'));
        }

        $rows = $query->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn (CollectionEntry $e) => $this->present($e))->values(),
        ]);
    }

    /**
     * GET /api/pending-relocations/count
     *
     * Compact endpoint for nav badging — same shape as the sidebar
     * payload's pending block, but reachable without the full
     * /location-groups round-trip. Returns 0 when the bucket is empty
     * or doesn't exist; never 404s.
     */
    public function count(): JsonResponse
    {
        $userId = auth()->id();
        $bucket = $this->pendingBucket($userId);
        $count  = $bucket === null
            ? 0
            : CollectionEntry::where('location_id', $bucket->id)->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/pending-relocations/resolve
     *
     * Body shape:
     *   {
     *     "assignments": [
     *       { "collection_entry_id": 12, "target_location_id": 5 },
     *       { "collection_entry_id": 13, "discard": true },
     *       { "collection_entry_id": 14 }   // skipped
     *     ]
     *   }
     *
     * Per-row outcome:
     *   - target_location_id set → move CE there, clear the source-deck
     *     stamp, merge into a matching CE if one already exists at the
     *     destination.
     *   - discard=true → delete the CE outright.
     *   - neither → skip (lets the SPA keep partially-resolved rows
     *     uncommitted across the same Apply click).
     *
     * @return JsonResponse  { resolved: int, merged: int, discarded: int, skipped: int }
     */
    public function resolve(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $data = $request->validate([
            'assignments'                        => 'required|array|min:1',
            'assignments.*.collection_entry_id'  => 'required|integer',
            'assignments.*.target_location_id'   => 'sometimes|nullable|integer',
            'assignments.*.discard'              => 'sometimes|boolean',
        ]);

        $resolved  = 0;
        $merged    = 0;
        $discarded = 0;
        $skipped   = 0;

        // Touched locations need a set_codes refresh after the move so
        // the sidebar chips drop / pick up codes. Collected here, run
        // outside the transaction once the writes are visible.
        $touchedLocationIds = [];

        DB::transaction(function () use (
            $data, $userId, &$resolved, &$merged, &$discarded, &$skipped, &$touchedLocationIds,
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

                $sourceLocId = $copy->location_id;
                $discard     = (bool) ($row['discard'] ?? false);
                $target      = array_key_exists('target_location_id', $row)
                    ? $row['target_location_id']
                    : null;

                if ($discard) {
                    $copy->delete();
                    $discarded++;
                    if ($sourceLocId !== null) $touchedLocationIds[$sourceLocId] = true;
                    continue;
                }

                if ($target === null) {
                    // No-op skip — SPA may have left this row
                    // unselected on purpose so the user can come back
                    // to it.
                    $skipped++;
                    continue;
                }

                // Must be a user-managed location belonging to the
                // caller. Auto-managed rows (deck/pending) are off-
                // limits as resolution targets.
                $targetLoc = Location::query()
                    ->where('id', $target)
                    ->where('user_id', $userId)
                    ->where('role', Location::ROLE_USER)
                    ->first();
                if ($targetLoc === null) {
                    $skipped++;
                    continue;
                }

                // Merge: if the destination already has a CE with the
                // same printing + condition + foil, sum quantities and
                // delete the pending row.
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
            'skipped'   => $skipped,
        ]);
    }

    private function pendingBucket(int $userId): ?Location
    {
        return Location::query()
            ->where('user_id', $userId)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->first();
    }

    /**
     * Presenter — flat shape the SPA renders directly. The
     * `source_deck` block prefers the live Deck record (so renames
     * track), falls back to the snapshot once the deck is gone.
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
            'id'          => $entry->id,
            'quantity'    => (int) $entry->quantity,
            'condition'   => $entry->condition,
            'foil'        => (bool) $entry->foil,
            'notes'       => $entry->notes,
            'source_deck' => $sourceDeck,
            'card'        => $card ? [
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
