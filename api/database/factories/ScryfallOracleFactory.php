<?php

namespace Database\Factories;

use App\Models\ScryfallOracle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScryfallOracle>
 */
class ScryfallOracleFactory extends Factory
{
    protected $model = ScryfallOracle::class;

    public function definition(): array
    {
        $oracleId = (string) Str::uuid();
        return [
            'oracle_id'              => $oracleId,
            // NOT NULL on the table; tests that bypass the paired
            // ScryfallCardFactory still need plausible values here.
            'default_scryfall_id'    => (string) Str::uuid(),
            'default_set_code'       => strtolower($this->faker->lexify('???')),
            'default_collector_number' => (string) $this->faker->numberBetween(1, 400),
            'default_rarity'         => $this->faker->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'name'                   => $this->faker->words(3, true),
            'layout'                 => 'normal',
            'is_dfc'                 => false,
            'mana_cost'              => '{1}{G}',
            'cmc'                    => 2.0,
            'colors'                 => ['G'],
            'color_identity'         => ['G'],
            'type_line'              => 'Creature — Elf',
            'supertypes'             => [],
            'types'                  => ['Creature'],
            'subtypes'               => ['Elf'],
            'oracle_text'            => null,
            'legalities'             => ['commander' => 'legal', 'standard' => 'legal', 'modern' => 'legal', 'pauper' => 'legal', 'oathbreaker' => 'legal'],
            'keywords'               => [],
            'reserved'               => false,
            'commander_game_changer' => false,
            'partner_scope'          => null,
            'printing_count'         => 1,
            'is_playtest_any'        => false,
            'excluded_from_catalog'  => false,
            'is_transform'           => false,
            'is_mdfc'                => false,
            'is_flip'                => false,
            'is_meld'                => false,
            'is_split'               => false,
            'is_leveler'             => false,
            'color_identity_bits'    => 16, // G
            'colors_bits'            => 16,
        ];
    }
}
