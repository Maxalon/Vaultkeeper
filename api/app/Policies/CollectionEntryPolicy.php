<?php

namespace App\Policies;

use App\Models\CollectionEntry;
use App\Models\Friendship;
use App\Models\User;

/**
 * Authorization gates for CollectionEntry access.
 *
 * Ownership rules (existing behaviour, unchanged):
 *   - create, update, delete — owner only.
 *   - view — owner OR accepted friend with collection_visibility='friends'.
 *
 * Friend-read rule (new in A2):
 *   To read another user's collection entry:
 *     1. The authenticated user must have an accepted friendship with the
 *        entry's owner.
 *     2. The owner's `collection_visibility` must be 'friends' (not 'private').
 *
 * The policy does NOT restrict which specific entries are visible — that is
 * handled at the query level (e.g. UserCollectionController) which only
 * returns entries in user-role locations.
 */
class CollectionEntryPolicy
{
    /**
     * Owner may always view their own entries.
     * Accepted friends may view when collection_visibility = 'friends'.
     */
    public function view(User $user, CollectionEntry $entry): bool
    {
        if ($user->id === $entry->user_id) {
            return true;
        }

        return $this->friendCanReadCollection($user, $entry->user_id);
    }

    /**
     * Only the owner may create entries.
     */
    public function create(User $user): bool
    {
        return true; // Controller scopes creation to authenticated user
    }

    /**
     * Only the owner may update entries.
     */
    public function update(User $user, CollectionEntry $entry): bool
    {
        return $user->id === $entry->user_id;
    }

    /**
     * Only the owner may delete entries.
     */
    public function delete(User $user, CollectionEntry $entry): bool
    {
        return $user->id === $entry->user_id;
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Returns true if the authenticated user ($viewer) has an accepted
     * friendship with $ownerId AND that owner's collection_visibility = 'friends'.
     */
    private function friendCanReadCollection(User $viewer, int $ownerId): bool
    {
        $friendship = Friendship::query()
            ->accepted()
            ->where(function ($q) use ($viewer, $ownerId) {
                $q->where(function ($inner) use ($viewer, $ownerId) {
                    $inner->where('user_a_id', min($viewer->id, $ownerId))
                        ->where('user_b_id', max($viewer->id, $ownerId));
                });
            })
            ->exists();

        if (! $friendship) {
            return false;
        }

        $owner = User::find($ownerId);
        if ($owner === null) {
            return false;
        }

        $privacy = $owner->privacySettings;

        return $privacy === null || $privacy->collection_visibility === 'friends';
    }
}
