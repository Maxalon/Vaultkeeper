<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\Friendship;
use App\Models\User;

/**
 * Authorization gates for Deck access.
 *
 * Ownership rules (existing behaviour, unchanged):
 *   - create, update, delete, assemble — owner only.
 *   - view — owner OR accepted friend with decks_visibility='friends'.
 *
 * Friend-read rule (new in A2):
 *   To read another user's deck:
 *     1. The authenticated user must have an accepted friendship with the
 *        deck's owner.
 *     2. The owner's `decks_visibility` must be 'friends' (not 'private').
 */
class DeckPolicy
{
    /**
     * Owner may always view their own decks.
     * Accepted friends may view when decks_visibility = 'friends'.
     */
    public function view(User $user, Deck $deck): bool
    {
        if ($user->id === $deck->user_id) {
            return true;
        }

        return $this->friendCanReadDecks($user, $deck->user_id);
    }

    /**
     * Only the owner may modify or delete decks.
     */
    public function update(User $user, Deck $deck): bool
    {
        return $user->id === $deck->user_id;
    }

    public function delete(User $user, Deck $deck): bool
    {
        return $user->id === $deck->user_id;
    }

    /**
     * Only the owner may assemble/unassemble a deck.
     */
    public function assemble(User $user, Deck $deck): bool
    {
        return $user->id === $deck->user_id;
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Returns true if the authenticated user ($viewer) has an accepted
     * friendship with $ownerId AND that owner's decks_visibility = 'friends'.
     */
    private function friendCanReadDecks(User $viewer, int $ownerId): bool
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

        return $privacy === null || $privacy->decks_visibility === 'friends';
    }
}
