<?php

namespace Database\Factories;

use App\Models\CollectionEntry;
use App\Models\User;
use App\Models\UserCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionEntry>
 */
class CollectionEntryFactory extends Factory
{
    protected $model = CollectionEntry::class;

    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'scryfall_id' => fn () => UserCard::factory()->create()->scryfall_id,
            'location_id' => null,
            'quantity'    => 1,
            'condition'   => 'NM',
            'foil'        => false,
            'notes'       => null,
        ];
    }
}
