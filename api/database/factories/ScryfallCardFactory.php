<?php

namespace Database\Factories;

use App\Models\ScryfallCard;
use App\Models\ScryfallOracle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScryfallCard>
 *
 * Tests can pass oracle-invariant attributes (type_line, cmc, colors,
 * legalities, keywords, …) to create() / make() as if they were native
 * ScryfallCard columns — the factory peels those off into a paired
 * ScryfallOracle row keyed by oracle_id. Existing test code keeps
 * working unchanged after issue #33's column move.
 */
class ScryfallCardFactory extends Factory
{
    protected $model = ScryfallCard::class;

    /**
     * Fields that no longer live on scryfall_cards. Anything the caller
     * passes in this set is routed to the paired scryfall_oracles row
     * instead of being silently dropped by $fillable.
     */
    private const ORACLE_ONLY_FIELDS = [
        'mana_cost', 'cmc', 'colors', 'color_identity',
        'type_line', 'supertypes', 'types', 'subtypes',
        'oracle_text', 'power', 'toughness', 'loyalty',
        'legalities', 'keywords', 'edhrec_rank', 'reserved',
    ];

    /**
     * Fields that live on BOTH tables (scryfall_cards keeps them
     * per-printing; scryfall_oracles holds the rep's value). Mirrored.
     */
    private const SHARED_FIELDS = [
        'commander_game_changer', 'partner_scope',
    ];

    /**
     * Per-card stash of oracle-invariant attrs picked off in newModel(),
     * keyed by spl_object_id. Drained by the afterCreating callback.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $oracleStash = [];

    public function definition(): array
    {
        return [
            'scryfall_id'            => (string) Str::uuid(),
            'oracle_id'              => (string) Str::uuid(),
            'name'                   => $this->faker->words(3, true),
            'set_code'               => strtolower($this->faker->lexify('???')),
            'collector_number'       => (string) $this->faker->numberBetween(1, 400),
            'rarity'                 => $this->faker->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'layout'                 => 'normal',
            'is_dfc'                 => false,
            'commander_game_changer' => false,
            'partner_scope'          => null,
        ];
    }

    /**
     * Intercept the merged attribute set before it hits the model so
     * oracle-invariant fields don't get silently dropped by $fillable.
     */
    public function newModel(array $attributes = [])
    {
        $oracleAttrs = array_intersect_key(
            $attributes,
            array_flip(array_merge(self::ORACLE_ONLY_FIELDS, self::SHARED_FIELDS)),
        );
        $cardAttrs = array_diff_key($attributes, array_flip(self::ORACLE_ONLY_FIELDS));

        $card = parent::newModel($cardAttrs);
        self::$oracleStash[spl_object_id($card)] = $oracleAttrs;
        return $card;
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ScryfallCard $card) {
            $key = spl_object_id($card);
            $oracleAttrs = self::$oracleStash[$key] ?? [];
            unset(self::$oracleStash[$key]);

            // Defaults wide enough to satisfy every NOT NULL on
            // scryfall_oracles when the test only specifies a handful
            // of oracle-invariant fields. Keep aligned with the
            // historical ScryfallCardFactory shape so legacy assertions
            // still pass.
            $defaults = array_merge(
                ScryfallOracle::factory()->definition(),
                [
                    'oracle_id'                => $card->oracle_id,
                    'default_scryfall_id'      => $card->scryfall_id,
                    'default_set_code'         => $card->set_code,
                    'default_collector_number' => $card->collector_number,
                    'default_rarity'           => $card->rarity,
                    'default_released_at'      => $card->released_at,
                    'name'                     => $card->name,
                    'layout'                   => $card->layout ?? 'normal',
                    'is_dfc'                   => $card->is_dfc ?? false,
                    'commander_game_changer'   => $card->commander_game_changer ?? false,
                    'partner_scope'            => $card->partner_scope,
                    'printing_count'           => 1,
                ],
            );

            ScryfallOracle::query()->updateOrCreate(
                ['oracle_id' => $card->oracle_id],
                array_merge($defaults, $oracleAttrs),
            );

            // Refresh the in-memory relation so the very next read on
            // this instance sees the values the test just configured.
            $card->setRelation('oracle', ScryfallOracle::query()->where('oracle_id', $card->oracle_id)->first());
        });
    }
}
