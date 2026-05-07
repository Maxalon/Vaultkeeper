<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserPrivacySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of a friend's collection and deck list.
 *
 * These endpoints are intentionally separate from CollectionController and
 * DeckController so authorization logic for friend-visibility doesn't
 * pollute the owner-only controllers.
 *
 * Access rules:
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
        $owner = User::findOrFail($user);

        $this->authorizeCollectionRead($request->user(), $owner);

        // A2 stub — full query implemented in A3.
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
        $owner = User::findOrFail($user);

        $this->authorizeDecksRead($request->user(), $owner);

        // A2 stub — full query implemented in A3.
        return response()->json(['data' => []]);
    }

    // ---------------------------------------------------------------------------
    // Authorization helpers (inline — not using the policy gate to avoid
    // auto-discovery coupling; the checks mirror CollectionEntryPolicy exactly)
    // ---------------------------------------------------------------------------

    private function authorizeCollectionRead(User $viewer, User $owner): void
    {
        if ($viewer->id === $owner->id) {
            // Use /api/collection for your own data, not this endpoint.
            abort(403, 'Use /api/collection to access your own collection.');
        }

        $isFriend = Friendship::query()
            ->accepted()
            ->where('user_a_id', min($viewer->id, $owner->id))
            ->where('user_b_id', max($viewer->id, $owner->id))
            ->exists();

        if (! $isFriend) {
            abort(403, 'You must be an accepted friend to view this collection.');
        }

        $privacy = UserPrivacySetting::where('user_id', $owner->id)->first();
        if ($privacy && $privacy->collection_visibility === 'private') {
            abort(403, 'This user has made their collection private.');
        }
    }

    private function authorizeDecksRead(User $viewer, User $owner): void
    {
        if ($viewer->id === $owner->id) {
            abort(403, 'Use /api/decks to access your own decks.');
        }

        $isFriend = Friendship::query()
            ->accepted()
            ->where('user_a_id', min($viewer->id, $owner->id))
            ->where('user_b_id', max($viewer->id, $owner->id))
            ->exists();

        if (! $isFriend) {
            abort(403, 'You must be an accepted friend to view this user\'s decks.');
        }

        $privacy = UserPrivacySetting::where('user_id', $owner->id)->first();
        if ($privacy && $privacy->decks_visibility === 'private') {
            abort(403, 'This user has made their decks private.');
        }
    }
}
