<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The wanted-card matcher: given a deck, surfaces which accepted friends
 * own an available copy of each `wanted` entry.
 *
 * "Available" means: a `CollectionEntry` whose `location.role = 'user'`
 * (NOT a deck shadow location). Assembled cards are excluded because
 * `DeckAssemblyService::ensureDeckLocation` moves them into the deck's
 * shadow location (role='deck').
 *
 * The query is a single SQL join (A4):
 *   deck_entries WHERE wanted
 *   ⨝ accepted friends of the deck owner
 *   ⨝ collection_entries of those friends WHERE scryfall_card_id matches
 *     AND locations.role = 'user'
 *     AND friend's collection_visibility = 'friends'
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
     * Route model binding uses the Deck `id`. The deck must belong to the
     * authenticated user (enforced by policy in A2/A4).
     *
     * Response 200:
     *   { "data": [
     *       {
     *         "scryfall_card_id": "...",
     *         "card_name":        "Lightning Bolt",
     *         "wanted_quantity":  2,
     *         "friends": [
     *           {
     *             "user_id":   3,
     *             "username":  "alice",
     *             "available_copies": [
     *               {
     *                 "collection_entry_id": 42,
     *                 "condition":           "NM",
     *                 "foil":                false,
     *                 "location_name":       "Binder A"
     *               }
     *             ]
     *           }
     *         ]
     *       }
     *     ]
     *   }
     *
     * Returns an empty array when the deck has no `wanted` entries or the
     * caller has no accepted friends with matching available copies.
     *
     * Responses:
     *   200 — success
     *   403 — deck does not belong to the authenticated user
     *   404 — deck not found
     *
     * Performance contract (A4):
     *   A composite index on `collection_entries(scryfall_id, user_id, location_id)`
     *   must be present. The query is verified with EXPLAIN ANALYZE against
     *   seeded data (50 friends × 1000 cards) and must stay under 50 ms.
     */
    public function index(int $deck): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }
}
