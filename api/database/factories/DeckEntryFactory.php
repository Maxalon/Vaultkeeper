<?php

namespace Database\Factories;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeckEntry>
 */
class DeckEntryFactory extends Factory
{
    protected $model = DeckEntry::class;

    public function definition(): array
    {
        return [
            'deck_id'     => Deck::factory(),
            'scryfall_id' => fn () => ScryfallCard::factory()->create()->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
            'wanted'      => false,
        ];
    }

    /**
     * Mark this entry as wanted.
     */
    public function wanted(): static
    {
        return $this->state(['wanted' => true]);
    }
}
