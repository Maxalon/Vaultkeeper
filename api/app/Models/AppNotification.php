<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centralized notification inbox entry (product decision 7).
 *
 * Named AppNotification to avoid collision with Laravel's built-in
 * Illuminate\Notifications\DatabaseNotification model and the
 * Notifiable trait's `notifications()` relation on User.
 *
 * The `actions` array is declarative JSON — each element has enough
 * metadata for the gateway endpoint to re-execute it server-side after
 * re-checking staleness via HasOptimisticVersion.
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'payload',
        'actions',
        'read_at',
    ];

    protected $casts = [
        'payload'  => 'array',
        'actions'  => 'array',
        'read_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------------

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Find the action with the given key, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findAction(string $key): ?array
    {
        foreach (($this->actions ?? []) as $action) {
            if (($action['key'] ?? null) === $key) {
                return $action;
            }
        }

        return null;
    }
}
