<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\Friendship;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The wanted-card matcher: given a deck, surfaces which accepted friends
 * own an available copy of each `wanted` entry.
 *
 * "Available" means: a `CollectionEntry` whose `location.role = 'user'`
 * (NOT a deck shadow location). Assembled cards are excluded because
 * `DeckAssemblyService::ensureDeckLocation` moves them into the deck's
 * shadow location (role='deck').
 *
 * Query design (single SQL — verified with EXPLAIN ANALYZE in feature test):
 *
 *   1. Pull wanted scryfall_ids from deck_entries for the given deck.
 *   2. Pull accepted friend user_ids for the deck owner.
 *   3. Pull collection_entries WHERE scryfall_id IN (wanted_ids)
 *      AND user_id IN (friend_ids)
 *      AND location.role = 'user'
 *      AND friend's collection_visibility = 'friends'
 *
 * The composite index collection_entries(scryfall_id, user_id, location_id)
 * added in A1 migration `add_collection_matcher_index` enables an
 * index-range scan that satisfies steps 3 with no table scan.
 *
 * Route definition:
 *
 *   GET /decks/{deck}/wanted-matches
 */
class WantedMatchController extends Controller
{
    /**
     * GET /api/decks/{deck}/wanted-matches
     *
     * Returns the wanted-card match list for the authenticated user's deck.
     *
     * Responses:
     *   200 — { "data": [...] }
     *   403 — deck does not belong to the authenticated user
     *   404 — deck not found
     *
     * Performance contract: index-verified in feature test; must stay under
     * 50 ms with seeded data (50 friends × 1000 cards per A4 requirement).
     */
    public function index(Request $request, int $deck): JsonResponse
    {
        /** @var \App\Models\User $caller */
        $caller = $request->user();

        $deckModel = Deck::findOrFail($deck);

        if ($deckModel->user_id !== $caller->id) {
            abort(403, 'This deck does not belong to you.');
        }

        // -----------------------------------------------------------------------
        // Step 1: wanted scryfall_ids from this deck.
        // -----------------------------------------------------------------------
        // `wanted` was migrated from boolean to a nullable zone enum
        // ('main'|'side'|'maybe') in 2026_04_19_000007. Any non-null value
        // means the entry is on the wishlist.
        $wantedEntries = DB::table('deck_entries')
            ->where('deck_id', $deckModel->id)
            ->whereNotNull('wanted')
            ->select('scryfall_id', DB::raw('SUM(quantity) as wanted_quantity'))
            ->groupBy('scryfall_id')
            ->get()
            ->keyBy('scryfall_id');

        if ($wantedEntries->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $wantedScryfallIds = $wantedEntries->keys()->all();

        // -----------------------------------------------------------------------
        // Step 2: accepted friend user_ids whose collection_visibility = 'friends'.
        // -----------------------------------------------------------------------
        $friendships = Friendship::query()
            ->accepted()
            ->where(function (Builder $q) use ($caller) {
                $q->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->get(['user_a_id', 'user_b_id']);

        $friendIds = $friendships
            ->flatMap(fn ($f) => [$f->user_a_id, $f->user_b_id])
            ->unique()
            ->reject(fn ($id) => $id === $caller->id)
            ->values()
            ->all();

        if (empty($friendIds)) {
            return response()->json(['data' => []]);
        }

        // Restrict to friends whose collection_visibility = 'friends'.
        $visibleFriendIds = DB::table('users')
            ->whereIn('users.id', $friendIds)
            ->where(function ($q) {
                // Friends with explicit 'friends' visibility OR no settings row (default).
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('user_privacy_settings')
                        ->whereColumn('user_privacy_settings.user_id', 'users.id')
                        ->where('user_privacy_settings.collection_visibility', 'friends');
                })
                ->orWhereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('user_privacy_settings')
                        ->whereColumn('user_privacy_settings.user_id', 'users.id');
                });
            })
            ->pluck('users.id')
            ->all();

        if (empty($visibleFriendIds)) {
            return response()->json(['data' => []]);
        }

        // -----------------------------------------------------------------------
        // Step 3: single SQL — collection_entries join locations join users.
        // The composite index ce_matcher_scryfall_user_location handles the
        // (scryfall_id, user_id, location_id) predicate efficiently.
        // -----------------------------------------------------------------------
        $rows = DB::table('collection_entries as ce')
            ->join('locations as l', 'l.id', '=', 'ce.location_id')
            ->join('users as u', 'u.id', '=', 'ce.user_id')
            ->whereIn('ce.scryfall_id', $wantedScryfallIds)
            ->whereIn('ce.user_id', $visibleFriendIds)
            ->where('l.role', Location::ROLE_USER)
            ->where('ce.quantity', '>', 0)
            ->select([
                'ce.id as collection_entry_id',
                'ce.scryfall_id',
                'ce.user_id',
                'ce.quantity',
                'ce.condition',
                'ce.foil',
                'l.name as location_name',
                'u.username',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // -----------------------------------------------------------------------
        // Step 4: Fetch card names for the matching scryfall_ids.
        // -----------------------------------------------------------------------
        $cardNames = DB::table('scryfall_cards')
            ->whereIn('scryfall_id', $wantedScryfallIds)
            ->pluck('name', 'scryfall_id');

        // -----------------------------------------------------------------------
        // Step 5: Group results by scryfall_id → friends → copies.
        // -----------------------------------------------------------------------
        $grouped = $rows->groupBy('scryfall_id');

        $data = $grouped->map(function ($copies, $scryfallId) use ($wantedEntries, $cardNames) {
            $friends = $copies->groupBy('user_id')->map(function ($userCopies, $userId) {
                return [
                    'user_id'          => $userId,
                    'username'         => $userCopies->first()->username,
                    'available_copies' => $userCopies->map(fn ($row) => [
                        'collection_entry_id' => $row->collection_entry_id,
                        'condition'           => $row->condition,
                        'foil'                => (bool) $row->foil,
                        'location_name'       => $row->location_name,
                    ])->values()->all(),
                ];
            })->values();

            return [
                'scryfall_card_id' => $scryfallId,
                'card_name'        => $cardNames[$scryfallId] ?? null,
                'wanted_quantity'  => (int) ($wantedEntries[$scryfallId]->wanted_quantity ?? 1),
                'friends'          => $friends,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }
}
