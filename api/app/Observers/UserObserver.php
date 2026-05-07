<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserPrivacySetting;

/**
 * Fires lifecycle hooks on User model events.
 *
 * created:
 *   Eagerly creates a `user_privacy_settings` row with default values so the
 *   privacy endpoint always has a row to return without needing firstOrCreate
 *   on every request.
 */
class UserObserver
{
    public function created(User $user): void
    {
        UserPrivacySetting::create([
            'user_id'               => $user->id,
            'collection_visibility' => 'friends',
            'decks_visibility'      => 'friends',
            'discoverable'          => true,
        ]);
    }
}
