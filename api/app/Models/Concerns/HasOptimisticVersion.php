<?php

namespace App\Models\Concerns;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Optimistic-locking helper. Models using this trait gain:
 *
 *   - automatic `version` bump on every save (saving event), so any
 *     mutation increments the counter exactly once.
 *   - `assertVersion(int $expected)` — throws 412 Precondition Failed
 *     when the expected version doesn't match the current row.
 *
 * The pattern callers should follow on every CE-mutating endpoint:
 *
 *     DB::transaction(function () use (...) {
 *         $ce = CollectionEntry::lockForUpdate()->findOrFail($id);
 *         if ($request->has('version')) {
 *             $ce->assertVersion((int) $request->input('version'));
 *         }
 *         $ce->update(...);
 *     });
 *
 * The `version` field is optional in requests so existing clients that
 * haven't been updated yet keep working — opt-in by sending the field.
 */
trait HasOptimisticVersion
{
    public static function bootHasOptimisticVersion(): void
    {
        static::saving(function ($model) {
            // Don't double-bump when something else (e.g. the trait
            // itself, on a follow-up assertVersion-driven re-save) has
            // already touched `version` on this save cycle.
            if ($model->isDirty('version')) {
                return;
            }
            $model->version = (int) ($model->version ?? 0) + 1;
        });
    }

    /**
     * Assert that the model's current version matches the caller's
     * expected version. Throws 412 Precondition Failed on mismatch.
     */
    public function assertVersion(int $expected): void
    {
        if ((int) $this->version !== $expected) {
            throw new HttpException(412, "Stale data — version mismatch (expected {$expected}, got {$this->version}).");
        }
    }
}
