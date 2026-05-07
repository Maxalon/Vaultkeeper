<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centralized notification inbox.
 *
 * All notification types funnel through a single `notifications` table
 * (see plan §Data Model). The actions array is declarative — each action
 * carries enough metadata for the SPA to render a button and for the
 * gateway (below) to re-execute it server-side with staleness re-check.
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   GET  /notifications
 *   POST /notifications/{id}/read
 *   POST /notifications/read-all
 *   POST /notifications/{id}/actions/{key}
 */
class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     *
     * Returns the authenticated user's notification list, newest first.
     * Paginated (25 per page).
     *
     * Query params:
     *   unread=1   — only return notifications where read_at IS NULL
     *
     * Response 200:
     *   { "data": [
     *       {
     *         "id":         1,
     *         "type":       "friend.request_received",
     *         "payload":    { "requester_id": 3, "requester_username": "alice" },
     *         "read_at":    null,
     *         "created_at": "<iso8601>",
     *         "actions": [
     *           {
     *             "key":              "accept",
     *             "label":            "Accept",
     *             "kind":             "default",
     *             "endpoint":         "/friends/requests/7",
     *             "method":           "PATCH",
     *             "body":             { "action": "accept" },
     *             "invalidates_on":   [
     *               { "model": "Friendship", "id": 7, "field": "status" }
     *             ],
     *             "available":        true
     *           }
     *         ]
     *       }
     *     ],
     *     "meta": { "current_page": 1, "last_page": 1, "total": 1 }
     *   }
     *
     * The `available` field on each action is computed at read time by
     * checking the `invalidates_on` conditions against the current model
     * state. When `available=false` the SPA shows "no longer available"
     * rather than the action button.
     */
    public function index(Request $request): JsonResponse
    {
        // A0 stub.
        return response()->json([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     *
     * Marks a single notification as read (sets `read_at` = now).
     * Idempotent: calling it again on an already-read notification is a no-op.
     *
     * Responses:
     *   200 — { "data": { "id": 1, "read_at": "<iso8601>" } }
     *   404 — notification not found (or does not belong to caller)
     */
    public function markRead(int $id): JsonResponse
    {
        // A0 stub.
        return response()->json(['data' => ['id' => $id, 'read_at' => now()->toIso8601String()]]);
    }

    /**
     * POST /api/notifications/read-all
     *
     * Marks all unread notifications for the authenticated user as read.
     *
     * Response 200:
     *   { "marked_read": 5 }
     */
    public function markAllRead(): JsonResponse
    {
        // A0 stub.
        return response()->json(['marked_read' => 0]);
    }

    /**
     * POST /api/notifications/{id}/actions/{key}
     *
     * Server-side action gateway. Re-checks `invalidates_on` staleness
     * before proxying the action to the underlying endpoint.
     *
     * This is the ONLY route the SPA should call when executing a
     * notification action — direct calls to the underlying endpoint bypass
     * staleness re-validation and audit logging.
     *
     * Flow (A5):
     *   1. Load the notification (must belong to caller).
     *   2. Find the action with matching `key` in `actions`.
     *   3. Evaluate every `invalidates_on` condition. If any condition is
     *      met (record mutated / deleted), return 409 with the "stale action"
     *      body — the SPA should refresh the notification list.
     *   4. Execute the underlying HTTP action internally (no external HTTP
     *      round-trip — dispatch to the appropriate controller method).
     *   5. Return the underlying response.
     *
     * Responses:
     *   200/201/204 — underlying action succeeded (pass-through)
     *   404         — notification or action key not found
     *   409         — action is stale: { "message": "Action is no longer available.",
     *                                    "reason": "record_mutated" | "record_deleted" }
     *
     * The staleness mechanism (A5) uses `HasOptimisticVersion`: each
     * `invalidates_on` entry records the model's `version` at notification
     * creation time; the gateway compares it to the current version on
     * re-fetch. Version mismatch → stale.
     */
    public function executeAction(int $id, string $key): JsonResponse
    {
        // A0 stub.
        return response()->json(['message' => 'Action executed.']);
    }
}
