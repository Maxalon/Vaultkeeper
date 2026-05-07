<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of a friend's collection and deck list.
 *
 * These endpoints are intentionally separate from CollectionController and
 * DeckController so authorization logic for friend-visibility doesn't
 * pollute the owner-only controllers.
 *
 * Access rules (enforced by policies in A2):
 *   - caller must have an accepted friendship with {user}
 *   - {user}'s `user_privacy_settings.collection_visibility` must be 'friends'
 *     (not 'private') for GET /users/{user}/collection
 *   - {user}'s `user_privacy_settings.decks_visibility` must be 'friends'
 *     for GET /users/{user}/decks
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   GET /users/{user}/collection
 *   GET /users/{user}/decks
 */
class UserCollectionController extends Controller
{
    /**
     * GET /api/users/{user}/collection
     *
     * Returns the friend's collection entries (available copies only).
     * Response shape mirrors GET /api/collection for frontend reuse.
     *
     * Response 200:
     *   { "data": [ { "id": 7, "scryfall_id": "...", "quantity": 2,
     *                 "condition": "NM", "foil": false,
     *                 "location_name": "Binder" } ] }
     *
     * Responses:
     *   200 — success
     *   403 — not friends, or friend set collection_visibility='private'
     *   404 — user not found
     */
    public function collection(Request $request, int $user): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }

    /**
     * GET /api/users/{user}/decks
     *
     * Returns the friend's deck list (deck metadata only, no entries).
     * Response shape mirrors GET /api/decks for frontend reuse.
     *
     * Responses:
     *   200 — success
     *   403 — not friends, or friend set decks_visibility='private'
     *   404 — user not found
     */
    public function decks(Request $request, int $user): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }
}
