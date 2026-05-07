<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the lifecycle of friend requests (pending phase only).
 *
 * Once a request is accepted or declined the row moves to
 * FriendController territory. This controller only handles the
 * in-flight state.
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   POST   /friends/requests
 *   GET    /friends/requests
 *   PATCH  /friends/requests/{id}
 *   DELETE /friends/requests/{id}
 */
class FriendRequestController extends Controller
{
    /**
     * POST /api/friends/requests
     *
     * Send a friend request by username.
     *
     * Request body:
     *   { "username": "alice" }
     *
     * Responses:
     *   201 — request created
     *     { "data": { "id": 1, "status": "pending", "direction": "outgoing",
     *                 "user": { "id": 2, "username": "alice" },
     *                 "created_at": "<iso8601>" } }
     *   404 — user not found (also returned when `discoverable=false` to
     *          avoid confirming existence of non-discoverable accounts)
     *   409 — a request between these two users is already pending,
     *          accepted, or declined
     *          { "message": "A friendship or request already exists between these users." }
     *   422 — validation error (missing username, or username = self)
     *
     * Throttle note: inherits the global 120/min floor. No extra sub-throttle
     * because the uniqueness check already prevents spam; /users/search (which
     * the SPA calls before this) is 30/min.
     */
    public function store(Request $request): JsonResponse
    {
        // A0 stub — returns shaped placeholder until A3 is implemented.
        return response()->json([
            'data' => [
                'id'         => 0,
                'status'     => 'pending',
                'direction'  => 'outgoing',
                'user'       => ['id' => 0, 'username' => ''],
                'created_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * GET /api/friends/requests
     *
     * List friend requests for the authenticated user.
     *
     * Query params:
     *   direction=incoming|outgoing  (default: both)
     *
     * Response 200:
     *   { "data": [
     *       { "id": 1, "status": "pending", "direction": "incoming",
     *         "user": { "id": 3, "username": "bob" },
     *         "created_at": "<iso8601>" }
     *     ]
     *   }
     */
    public function index(Request $request): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }

    /**
     * PATCH /api/friends/requests/{id}
     *
     * Accept or decline a pending request. Only the addressee (the user who
     * received the request) may call this endpoint.
     *
     * Request body:
     *   { "action": "accept" | "decline" }
     *
     * Responses:
     *   200 — accepted: { "data": { "id": 1, "status": "accepted" } }
     *         declined: { "data": { "id": 1, "status": "declined" } }
     *   403 — caller is not the addressee
     *   404 — request not found
     *   409 — request is no longer in `pending` state
     *   422 — unknown action value
     *
     * Side-effects (A5):
     *   - accept → queued notification to the requester
     *             (`friend.request_accepted`)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => ['id' => $id, 'status' => 'accepted']]);
    }

    /**
     * DELETE /api/friends/requests/{id}
     *
     * Cancel (withdraw) an outgoing pending request. Only the requester may
     * call this endpoint.
     *
     * Responses:
     *   204 — cancelled
     *   403 — caller is not the requester
     *   404 — request not found
     *   409 — request is no longer in `pending` state (already accepted /
     *          declined)
     */
    public function destroy(int $id): JsonResponse
    {
        // A0 stub.
        return response()->json(null, 204);
    }
}
