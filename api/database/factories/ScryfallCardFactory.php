<?php

namespace Database\Factories;

use App\Models\ScryfallCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScryfallCard>
 */
class ScryfallCardFactory extends Factory
{
    protected $model = ScryfallCard::class;

    public function definition(): array
    {
        return [
            'scryfall_id'       => (string) Str::uuid(),
            'oracle_id'         => (string) Str::uuid(),
            'name'              => $this->faker->words(3, true),
            'set_code'          => strtolower($this->faker->lexify('???')),
            'collector_number'  => (string) $this->faker->numberBetween(1, 400),
            'rarity'            => $this->faker->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'layout'            => 'normal',
            'is_dfc'            => false,
            'mana_cost'         => '{1}{G}',
            'cmc'               => 2.0,
            'colors'            => ['G'],
            'color_identity'    => ['G'],
            'type_line'         => 'Creature — Elf',
            'oracle_text'       => null,
            'legalities'        => ['commander' => 'legal', 'standard' => 'legal', 'modern' => 'legal', 'pauper' => 'legal', 'oathbreaker' => 'legal'],
            'keywords'          => [],
            'reserved'          => false,
            'commander_game_changer' => false,
            'partner_scope'     => null,
        ];
    }
}
