<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Username prefix search for the friend-discovery flow.
 *
 * Privacy rules enforced at query time:
 *   - excludes users with `discoverable = false`
 *   - excludes self
 *   - excludes users who are already accepted friends of the caller
 *   - excludes users for whom a pending request already exists (in either
 *     direction) — prevents the SPA from offering a "Send request" button
 *     that would return 409
 *
 * Route definition:
 *
 *   GET /users/search   (throttle:30,1 — tighter than default)
 */
class UserSearchController extends Controller
{
    /**
     * GET /api/users/search?q=
     *
     * Username prefix search.
     *
     * Query params:
     *   q   (required) — prefix string, min 1 char, max 50 chars
     *
     * Responses:
     *   200:
     *     { "data": [
     *         { "id": 3, "username": "charlie" }
     *       ]
     *     }
     *   422 — missing or invalid `q`
     *
     * Results are limited to 20 rows and ordered by username ASC.
     * Email is never returned (decision 4).
     *
     * Throttle: 30/min per authenticated user (applied on the route definition).
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:50'],
        ]);

        /** @var User $caller */
        $caller = $request->user();
        $q      = $request->query('q');

        // Collect IDs of users the caller already has any relationship with
        // (pending in either direction, accepted, or declined) so they're excluded.
        // We exclude all statuses — pending: dedup; accepted/declined: no re-request.
        $relatedIds = Friendship::query()
            ->where(function (Builder $query) use ($caller) {
                $query->where('user_a_id', $caller->id)
                    ->orWhere('user_b_id', $caller->id);
            })
            ->get(['user_a_id', 'user_b_id'])
            ->flatMap(fn ($f) => [$f->user_a_id, $f->user_b_id])
            ->unique()
            ->reject(fn ($id) => $id === $caller->id)
            ->values()
            ->all();

        $users = User::query()
            ->where('username', 'LIKE', $q.'%')
            ->where('id', '!=', $caller->id)
            ->whereNotIn('id', $relatedIds)
            // Exclude non-discoverable users.
            // Users with no privacy settings row are discoverable by default.
            ->where(function (Builder $q) {
                $q->whereDoesntHave('privacySettings')
                    ->orWhereHas('privacySettings', function (Builder $inner) {
                        $inner->where('discoverable', true);
                    });
            })
            ->orderBy('username')
            ->limit(20)
            ->get(['id', 'username']);

        return response()->json([
            'data' => $users->map(fn ($u) => ['id' => $u->id, 'username' => $u->username]),
        ]);
    }
}
