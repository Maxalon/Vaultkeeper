<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Central factory for creating in-app notifications (A5).
 *
 * Usage:
 *   app(NotificationService::class)->notify(
 *       user:    $recipient,
 *       type:    'friend.request_received',
 *       payload: ['requester_id' => 3, 'requester_username' => 'alice'],
 *       actions: [
 *           [
 *               'key'            => 'accept',
 *               'label'          => 'Accept',
 *               'kind'           => 'default',
 *               'endpoint'       => '/friends/requests/7',
 *               'method'         => 'PATCH',
 *               'body'           => ['action' => 'accept'],
 *               'invalidates_on' => [
 *                   ['model' => 'Friendship', 'id' => 7, 'version' => 0]
 *               ],
 *           ],
 *       ],
 *   );
 *
 * Staleness detection (A5):
 *   Each action MAY carry an `invalidates_on` list. Each entry is:
 *     { model: string (short class name), id: int, version: int }
 *   When the notification gateway re-executes an action, it checks that
 *   every watched record's current `version` still matches the snapshot.
 *   If any record has been mutated (version bumped) or deleted, the
 *   gateway returns 409 Stale.
 *
 *   The `version` field is only meaningful for models that use the
 *   HasOptimisticVersion trait (currently: CollectionEntry, Friendship).
 */
class NotificationService
{
    /**
     * Persist a notification row for the given recipient.
     *
     * @param  User                                $user     Notification recipient.
     * @param  string                              $type     Dot-namespaced type, e.g. 'friend.request_received'.
     * @param  array<string, mixed>                $payload  Type-specific context data.
     * @param  array<int, array<string, mixed>>    $actions  Optional declarative action buttons.
     */
    public function notify(
        User $user,
        string $type,
        array $payload,
        array $actions = [],
    ): AppNotification {
        return AppNotification::create([
            'user_id' => $user->id,
            'type'    => $type,
            'payload' => $payload,
            'actions' => empty($actions) ? null : $actions,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Staleness helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a single `invalidates_on` entry for a HasOptimisticVersion model.
     * Snapshots the model's current `version` at notification-creation time.
     *
     * @return array<string, mixed>
     */
    public static function invalidatesOn(Model $model): array
    {
        return [
            'model'   => class_basename($model),
            'id'      => $model->getKey(),
            'version' => (int) ($model->version ?? 0),
        ];
    }

    /**
     * Evaluate every `invalidates_on` condition for one action.
     *
     * Returns true when the action is still available (all records
     * exist and none have been mutated since the snapshot).
     * Returns false when any watched record is missing or stale.
     *
     * @param  array<string, mixed>  $action  One action element from the
     *                                         notification's `actions` array.
     */
    public static function isActionAvailable(array $action): bool
    {
        foreach ($action['invalidates_on'] ?? [] as $condition) {
            $modelClass = self::resolveModelClass($condition['model'] ?? '');
            if ($modelClass === null) {
                Log::warning('NotificationService: unknown model in invalidates_on', $condition);
                return false;
            }

            $record = $modelClass::find($condition['id'] ?? null);
            if ($record === null) {
                // Record deleted — action is stale.
                return false;
            }

            $snapshotVersion = (int) ($condition['version'] ?? 0);
            $currentVersion  = (int) ($record->version ?? 0);
            if ($currentVersion !== $snapshotVersion) {
                // Record mutated — action is stale.
                return false;
            }
        }

        return true;
    }

    /**
     * Map a short model name (e.g. "Friendship") to its fully-qualified class.
     * Only models that carry HasOptimisticVersion are valid targets.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private static function resolveModelClass(string $shortName): ?string
    {
        return match ($shortName) {
            'Friendship'      => \App\Models\Friendship::class,
            'CollectionEntry' => \App\Models\CollectionEntry::class,
            default           => null,
        };
    }
}
