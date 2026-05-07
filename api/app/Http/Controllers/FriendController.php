<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * The `friends_since` timestamp is `responded_at` — the moment the
     * request moved to `accepted`.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $caller */
        $caller = $request->user();

        $friendships = Friendship::query()
            ->accepted()
            ->where(function ($q) use ($caller) {
                $q->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->with(['userA', 'userB'])
            ->orderBy('responded_at', 'desc')
            ->get();

        $friends = $friendships->map(function (Friendship $f) use ($caller) {
            $other = $f->otherUser($caller);

            return [
                'id'           => $other->id,
                'username'     => $other->username,
                'friends_since' => $f->responded_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $friends]);
    }

    /**
     * DELETE /api/friends/{user}
     *
     * Unfriend the given user. Symmetric: the single `friendships` row is
     * deleted; both sides lose the relationship simultaneously.
     *
     * Note: PM carry-forward from #211 review — full route model binding
     * (User $user) will be used when A3 wires the route properly. For now
     * we accept the raw id and look up the friendship manually.
     *
     * Responses:
     *   204 — unfriended
     *   404 — no accepted friendship with that user
     *
     * Side-effects: no notification is sent for unfriending (decision 5).
     */
    public function destroy(Request $request, int $user): JsonResponse
    {
        /** @var User $caller */
        $caller = $request->user();

        $friendship = Friendship::query()
            ->accepted()
            ->where('user_a_id', min($caller->id, $user))
            ->where('user_b_id', max($caller->id, $user))
            ->first();

        if ($friendship === null) {
            return response()->json(['message' => 'No accepted friendship found with this user.'], 404);
        }

        $friendship->delete();

        return response()->json(null, 204);
    }
}
