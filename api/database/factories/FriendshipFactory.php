<?php

namespace Database\Factories;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Friendship>
 */
class FriendshipFactory extends Factory
{
    protected $model = Friendship::class;

    public function definition(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        [$minId, $maxId] = $userA->id < $userB->id
            ? [$userA->id, $userB->id]
            : [$userB->id, $userA->id];

        return [
            'user_a_id'    => $minId,
            'user_b_id'    => $maxId,
            'requester_id' => $userA->id,
            'status'       => 'pending',
            'responded_at' => null,
        ];
    }

    /**
     * Create an accepted friendship between two specific users.
     */
    public function accepted(): static
    {
        return $this->state([
            'status'       => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /**
     * Create a declined friendship.
     */
    public function declined(): static
    {
        return $this->state([
            'status'       => 'declined',
            'responded_at' => now(),
        ]);
    }

    /**
     * Build a friendship between two specific users (canonical ordering applied).
     */
    public function between(User $a, User $b): static
    {
        $pair = Friendship::canonicalPair($a->id, $b->id);

        return $this->state([
            'user_a_id'    => $pair['user_a_id'],
            'user_b_id'    => $pair['user_b_id'],
            'requester_id' => $a->id,
        ]);
    }
}
