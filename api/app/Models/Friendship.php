<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a friend relationship (or pending request) between two users.
 *
 * The canonical row always stores user_a_id = min(uid_1, uid_2) and
 * user_b_id = max(uid_1, uid_2). This guarantees exactly one row per pair
 * and makes dedup a simple unique-constraint check.
 *
 * Status lifecycle:
 *   pending  → accepted  (addressee accepts)
 *   pending  → declined  (addressee declines)
 *   declined is terminal — the application layer returns 409 on re-request.
 */
class Friendship extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_a_id',
        'user_b_id',
        'requester_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    // ---------------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------------

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    // ---------------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------------

    /**
     * Only rows in `status = 'accepted'`.
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Rows that are pending and where $user is the addressee (i.e. NOT the
     * requester). Used to find incoming requests the user can accept/decline.
     */
    public function scopePendingFor(Builder $query, User $user): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where(function (Builder $q) use ($user) {
                $q->where('user_a_id', $user->id)
                    ->orWhere('user_b_id', $user->id);
            })
            ->where('requester_id', '!=', $user->id);
    }

    /**
     * The single row (if any) that links the two given users, regardless of
     * which side is user_a vs user_b. Enforces the canonical ordering so
     * callers don't have to.
     */
    public function scopeBetweenUsers(Builder $query, User $a, User $b): Builder
    {
        [$minId, $maxId] = $a->id < $b->id
            ? [$a->id, $b->id]
            : [$b->id, $a->id];

        return $query->where('user_a_id', $minId)->where('user_b_id', $maxId);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build the canonical (least, greatest) pair from a requester and addressee.
     * Returns ['user_a_id' => ..., 'user_b_id' => ...] ready for insert/lookup.
     */
    public static function canonicalPair(int $requesterId, int $addresseeId): array
    {
        return [
            'user_a_id' => min($requesterId, $addresseeId),
            'user_b_id' => max($requesterId, $addresseeId),
        ];
    }

    /**
     * True when the given user is one of the two participants in this friendship.
     */
    public function involves(User $user): bool
    {
        return $this->user_a_id === $user->id || $this->user_b_id === $user->id;
    }

    /**
     * Returns the "other" participant from the caller's perspective.
     */
    public function otherUser(User $user): User
    {
        if ($this->user_a_id === $user->id) {
            return $this->userB;
        }

        return $this->userA;
    }
}
