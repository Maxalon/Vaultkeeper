<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Privacy settings for a single user. Created on first access via
 * `firstOrCreate` or eagerly by the User::created observer. `user_id` is
 * also the primary key (one row per user).
 *
 * Visibility enums are intentionally limited to ('friends', 'private') —
 * 'public' does not exist (product decision 2).
 */
class UserPrivacySetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'collection_visibility',
        'decks_visibility',
        'discoverable',
    ];

    protected $casts = [
        'discoverable' => 'boolean',
    ];

    protected $attributes = [
        'collection_visibility' => 'friends',
        'decks_visibility'      => 'friends',
        'discoverable'          => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------------------
    // Convenience accessors
    // ---------------------------------------------------------------------------

    public function collectionVisibleToFriends(): bool
    {
        return $this->collection_visibility === 'friends';
    }

    public function decksVisibleToFriends(): bool
    {
        return $this->decks_visibility === 'friends';
    }
}
