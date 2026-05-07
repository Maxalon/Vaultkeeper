<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Manages the accepted-friends list and unfriending.
 *
 * Friend requests (pending phase) are handled by FriendRequestController.
 * This controller only sees rows in `status = 'accepted'`.
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   GET    /friends
 *   DELETE /friends/{user}
 */
class FriendController extends Controller
{
    /**
     * GET /api/friends
     *
     * Returns the accepted friends list for the authenticated user.
     *
     * Response 200:
     *   { "data": [
     *       { "id": 2, "username": "alice", "friends_since": "<iso8601>" }
     *     ]
     *   }
     *
     * The `friends_since` timestamp is the `updated_at` of the friendship
     * row at the moment it moved to `accepted` (stored in `responded_at`).
     */
    public function index(): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }

    /**
     * DELETE /api/friends/{user}
     *
     * Unfriend the given user. Symmetric: the single `friendships` row is
     * deleted; both sides lose the relationship simultaneously.
     *
     * Route model binding uses the User's `id`.
     *
     * Responses:
     *   204 — unfriended
     *   404 — no accepted friendship with that user
     *
     * Side-effects: no notification is sent for unfriending (decision 5).
     */
    public function destroy(int $user): JsonResponse
    {
        // A0 stub.
        return response()->json(null, 204);
    }
}
