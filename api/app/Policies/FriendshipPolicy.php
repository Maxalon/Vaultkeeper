<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;

/**
 * Authorization gates for friendship operations.
 *
 * Registered automatically via Laravel 11's policy auto-discovery
 * (App\Models\Friendship → App\Policies\FriendshipPolicy).
 *
 * Key rules:
 *  - Only the requester may cancel (delete) a pending request.
 *  - Only the addressee may accept or decline a pending request.
 *  - Either participant may read the friendship row.
 *  - Either participant may unfriend (delete an accepted row).
 */
class FriendshipPolicy
{
    /**
     * Either participant may view the friendship row.
     */
    public function view(User $user, Friendship $friendship): bool
    {
        return $friendship->involves($user);
    }

    /**
     * The requester may cancel their own outgoing pending request.
     */
    public function cancel(User $user, Friendship $friendship): bool
    {
        return $friendship->status === 'pending'
            && $friendship->requester_id === $user->id;
    }

    /**
     * Only the addressee (non-requester participant) may respond to a pending
     * request (accept or decline).
     */
    public function respond(User $user, Friendship $friendship): bool
    {
        return $friendship->status === 'pending'
            && $friendship->involves($user)
            && $friendship->requester_id !== $user->id;
    }

    /**
     * Either accepted-friendship participant may unfriend.
     */
    public function unfriend(User $user, Friendship $friendship): bool
    {
        return $friendship->status === 'accepted'
            && $friendship->involves($user);
    }
}
