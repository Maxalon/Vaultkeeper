<?php

namespace Database\Factories;

use App\Models\UserCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserCard>
 */
class UserCardFactory extends Factory
{
    protected $model = UserCard::class;

    public function definition(): array
    {
        return [
            'scryfall_id'        => (string) Str::uuid(),
            'name'               => fake()->unique()->words(2, true),
            'set_code'           => strtoupper(fake()->lexify('???')),
            'collector_number'   => (string) fake()->numberBetween(1, 400),
            'rarity'             => fake()->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'image_small'        => fake()->imageUrl(146, 204),
            'image_normal'       => fake()->imageUrl(488, 680),
            'image_large'        => fake()->imageUrl(672, 936),
            'colors'             => ['U'],
            'is_dfc'             => false,
            'last_scryfall_sync' => now(),
        ];
    }
}
