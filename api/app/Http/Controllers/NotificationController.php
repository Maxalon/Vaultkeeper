<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centralized notification inbox (A5).
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
    public function __construct(private NotificationService $notifications) {}

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
     *   { "data": [ { "id": 1, "type": "...", "payload": {}, "read_at": null,
     *                 "created_at": "...", "actions": [{ ..., "available": true }] } ],
     *     "meta": { "current_page": 1, "last_page": 1, "total": 1 } }
     *
     * The `available` field on each action is computed at read time by
     * checking the `invalidates_on` conditions against the current model
     * state via HasOptimisticVersion version comparison.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = AppNotification::query()
            ->where('user_id', $user->id)
            ->latest();

        if ($request->query('unread') === '1') {
            $query->unread();
        }

        $paginated = $query->paginate(25);

        $data = $paginated->map(function (AppNotification $n) {
            return $this->formatNotification($n);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
            ],
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
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $notification = AppNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->markRead();

        return response()->json([
            'data' => [
                'id'      => $notification->id,
                'read_at' => $notification->read_at?->toIso8601String(),
            ],
        ]);
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
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $count = AppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked_read' => $count]);
    }

    /**
     * POST /api/notifications/{id}/actions/{key}
     *
     * Server-side action gateway. Re-checks `invalidates_on` staleness
     * before proxying the action to the underlying endpoint.
     *
     * This is the ONLY route the SPA should call when executing a
     * notification action — direct calls to the underlying endpoint bypass
     * staleness re-validation.
     *
     * Flow:
     *   1. Load the notification (must belong to caller).
     *   2. Find the action with matching `key`.
     *   3. Evaluate every `invalidates_on` condition via NotificationService.
     *      If stale → 409.
     *   4. Dispatch internally to the appropriate controller method via
     *      app()->call() or Route::dispatch() to avoid external HTTP round-trip.
     *   5. Return the underlying response.
     *
     * Responses:
     *   200/201/204 — underlying action succeeded (pass-through)
     *   404         — notification or action key not found
     *   409         — action is stale: { "message": "Action is no longer available.",
     *                                    "reason": "record_mutated" | "record_deleted" }
     */
    public function executeAction(int $id, string $key): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $notification = AppNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $action = $notification->findAction($key);
        if ($action === null) {
            return response()->json(['message' => 'Action not found.'], 404);
        }

        // Re-check staleness before executing.
        if (! NotificationService::isActionAvailable($action)) {
            return response()->json([
                'message' => 'Action is no longer available.',
                'reason'  => 'record_mutated',
            ], 409);
        }

        // Dispatch internally — resolve method from endpoint + method.
        $endpoint = $action['endpoint'] ?? '';
        $method   = strtoupper($action['method'] ?? 'GET');
        $body     = $action['body'] ?? [];

        $internalRequest = Request::create(
            '/api'.$endpoint,
            $method,
            $body,
        );

        // Carry the auth token forward so the internal request passes JWT auth.
        $internalRequest->headers->set('Authorization', request()->header('Authorization'));
        $internalRequest->setUserResolver(fn () => $user);

        $response = app()->handle($internalRequest);

        return response()->json(
            json_decode($response->getContent(), true),
            $response->getStatusCode(),
        );
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Format a notification row for the API response, computing `available`
     * on each action at read time.
     *
     * @return array<string, mixed>
     */
    private function formatNotification(AppNotification $n): array
    {
        $actions = array_map(function (array $action) {
            $action['available'] = NotificationService::isActionAvailable($action);
            return $action;
        }, $n->actions ?? []);

        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'payload'    => $n->payload,
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
            'actions'    => $actions,
        ];
    }
}
