<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Username prefix search for the friend-discovery flow.
 *
 * Privacy rules enforced at query time (A3):
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
     * Throttle: 30/min per authenticated user (tighter than the 120/min
     * global floor). The sub-throttle is applied on the route definition
     * in api.php, not here.
     */
    public function search(Request $request): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => []]);
    }
}
