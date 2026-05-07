<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the authenticated user's privacy settings.
 *
 * Each user gets exactly one `user_privacy_settings` row (created with
 * defaults on first access or via a `User::created` observer in A2).
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   GET   /privacy-settings
 *   PATCH /privacy-settings
 */
class PrivacySettingController extends Controller
{
    /**
     * GET /api/privacy-settings
     *
     * Returns the caller's current privacy settings.
     *
     * Response 200:
     *   { "data": {
     *       "collection_visibility": "friends",
     *       "decks_visibility":      "friends",
     *       "discoverable":          true
     *     }
     *   }
     *
     * Notes:
     *   - `collection_visibility` and `decks_visibility` are enum('friends','private').
     *     There is no 'public' value — by design (decision 2).
     *   - `discoverable` controls whether the user appears in username search results.
     */
    public function show(): JsonResponse
    {
        // A0 stub.
        return response()->json([
            'data' => [
                'collection_visibility' => 'friends',
                'decks_visibility'      => 'friends',
                'discoverable'          => true,
            ],
        ]);
    }

    /**
     * PATCH /api/privacy-settings
     *
     * Updates one or more privacy settings.
     *
     * Request body (all fields optional):
     *   {
     *     "collection_visibility": "friends" | "private",
     *     "decks_visibility":      "friends" | "private",
     *     "discoverable":          true | false
     *   }
     *
     * Response 200:
     *   { "data": { <same shape as GET> } }
     *
     * Responses:
     *   200 — updated
     *   422 — unknown value for an enum field (e.g. "public" is rejected)
     */
    public function update(Request $request): JsonResponse
    {
        // A0 stub.
        return response()->json([
            'data' => [
                'collection_visibility' => 'friends',
                'decks_visibility'      => 'friends',
                'discoverable'          => true,
            ],
        ]);
    }
}
