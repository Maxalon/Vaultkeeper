<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $caller = $request->user();

        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        // Reject self-request.
        if ($data['username'] === $caller->username) {
            return response()->json([
                'message' => 'The username field must not be your own username.',
                'errors'  => ['username' => ['You cannot send a friend request to yourself.']],
            ], 422);
        }

        $target = User::where('username', $data['username'])->first();

        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Check for an existing relationship row.
        $existingRow = Friendship::query()
            ->where('user_a_id', min($caller->id, $target->id))
            ->where('user_b_id', max($caller->id, $target->id))
            ->first();

        if ($existingRow !== null) {
            return response()->json([
                'message' => 'A friendship or request already exists between these users.',
            ], 409);
        }

        // Enforce discoverability only when no prior relationship exists.
        $privacy = $target->privacySettings;
        if ($privacy && ! $privacy->discoverable) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $pair = Friendship::canonicalPair($caller->id, $target->id);

        $friendship = Friendship::create([
            'user_a_id'    => $pair['user_a_id'],
            'user_b_id'    => $pair['user_b_id'],
            'requester_id' => $caller->id,
            'status'       => 'pending',
        ]);

        $friendship->load(['userA', 'userB']);

        return response()->json([
            'data' => $this->formatRequest($friendship, $caller),
        ], 201);
    }

    /**
     * GET /api/friends/requests
     *
     * List friend requests for the authenticated user.
     *
     * Query params:
     *   direction=incoming|outgoing  (default: both — incoming + outgoing combined)
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
        $caller    = $request->user();
        $direction = $request->query('direction');

        $query = Friendship::query()
            ->where('status', 'pending')
            ->where(function ($q) use ($caller) {
                $q->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->with(['userA', 'userB']);

        if ($direction === 'incoming') {
            $query->where('requester_id', '!=', $caller->id);
        } elseif ($direction === 'outgoing') {
            $query->where('requester_id', $caller->id);
        }

        $friendships = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $friendships->map(fn ($f) => $this->formatRequest($f, $caller)),
        ]);
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
        $caller = $request->user();

        // Load only rows the caller participates in.
        $friendship = Friendship::query()
            ->where('id', $id)
            ->where(function ($q) use ($caller) {
                $q->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->first();

        if ($friendship === null) {
            return response()->json(['message' => 'Friend request not found.'], 404);
        }

        // Only the addressee (non-requester) may respond.
        if ($friendship->requester_id === $caller->id) {
            return response()->json(['message' => 'You cannot respond to your own request.'], 403);
        }

        if ($friendship->status !== 'pending') {
            return response()->json([
                'message' => 'This request is no longer pending.',
            ], 409);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'decline'])],
        ]);

        $newStatus = $data['action'] === 'accept' ? 'accepted' : 'declined';

        $friendship->update([
            'status'       => $newStatus,
            'responded_at' => now(),
        ]);

        // TODO (A5): dispatch friend.request_accepted notification when accepted.

        return response()->json([
            'data' => ['id' => $friendship->id, 'status' => $friendship->status],
        ]);
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
        /** @var \App\Models\User $caller */
        $caller = auth()->user();

        $friendship = Friendship::query()
            ->where('id', $id)
            ->where(function ($q) use ($caller) {
                $q->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->first();

        if ($friendship === null) {
            return response()->json(['message' => 'Friend request not found.'], 404);
        }

        if ($friendship->requester_id !== $caller->id) {
            return response()->json(['message' => 'You can only cancel your own requests.'], 403);
        }

        if ($friendship->status !== 'pending') {
            return response()->json([
                'message' => 'This request is no longer pending.',
            ], 409);
        }

        $friendship->delete();

        return response()->json(null, 204);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Format a Friendship row as a request DTO from the given user's perspective.
     *
     * @return array<string, mixed>
     */
    private function formatRequest(Friendship $friendship, User $caller): array
    {
        $isRequester = $friendship->requester_id === $caller->id;

        // The "other" participant from the caller's perspective.
        if ($isRequester) {
            $other = ($friendship->userA?->id === $caller->id)
                ? $friendship->userB
                : $friendship->userA;
        } else {
            $other = User::find($friendship->requester_id);
        }

        return [
            'id'         => $friendship->id,
            'status'     => $friendship->status,
            'direction'  => $isRequester ? 'outgoing' : 'incoming',
            'user'       => $other ? ['id' => $other->id, 'username' => $other->username] : null,
            'created_at' => $friendship->created_at?->toIso8601String(),
        ];
    }
}
