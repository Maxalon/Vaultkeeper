<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Flat denormalised oracle-level view of scryfall_cards. One row per
 * oracle_id, populated by BulkSyncService::syncOracleTable() at the end of
 * scryfall:sync-bulk. Catalog search queries this table instead of
 * window-wrapping scryfall_cards. See issue #30.
 *
 * Derived data — safe to TRUNCATE + rebuild. All reads (parser emits
 * against this table, controller SELECTs from it) go through here; writes
 * only come from BulkSyncService.
 */
class ScryfallOracle extends Model
{
    use HasFactory;

    protected $table = 'scryfall_oracles';

    protected $primaryKey = 'oracle_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'oracle_id',
        'default_scryfall_id',
        'default_set_code',
        'default_collector_number',
        'default_released_at',
        'default_rarity',
        'default_image_small',
        'default_image_normal',
        'default_image_large',
        'name',
        'layout',
        'is_dfc',
        'mana_cost',
        'cmc',
        'colors',
        'color_identity',
        'type_line',
        'supertypes',
        'types',
        'subtypes',
        'oracle_text',
        'printed_text',
        'power',
        'toughness',
        'loyalty',
        'legalities',
        'keywords',
        'edhrec_rank',
        'reserved',
        'commander_game_changer',
        'partner_scope',
        'mana_cost_back',
        'type_line_back',
        'oracle_text_back',
        'printed_text_back',
        'image_small_back',
        'image_normal_back',
        'image_large_back',
        'printing_count',
        'max_released_at',
        'is_playtest_any',
        'excluded_from_catalog',
        'is_transform',
        'is_mdfc',
        'is_flip',
        'is_meld',
        'is_split',
        'is_leveler',
        'color_identity_bits',
        'colors_bits',
        'last_synced_at',
    ];

    protected $casts = [
        'is_dfc'                 => 'boolean',
        'reserved'               => 'boolean',
        'commander_game_changer' => 'boolean',
        'is_playtest_any'        => 'boolean',
        'excluded_from_catalog'  => 'boolean',
        'is_transform'           => 'boolean',
        'is_mdfc'                => 'boolean',
        'is_flip'                => 'boolean',
        'is_meld'                => 'boolean',
        'is_split'               => 'boolean',
        'is_leveler'             => 'boolean',
        'colors'                 => 'array',
        'color_identity'         => 'array',
        'legalities'             => 'array',
        'keywords'               => 'array',
        'supertypes'             => 'array',
        'types'                  => 'array',
        'subtypes'               => 'array',
        'cmc'                    => 'decimal:2',
        'edhrec_rank'            => 'integer',
        'printing_count'         => 'integer',
        'color_identity_bits'    => 'integer',
        'colors_bits'            => 'integer',
        'default_released_at'    => 'date',
        'max_released_at'        => 'date',
        'last_synced_at'         => 'datetime',
    ];

    public function tags(): HasMany
    {
        return $this->hasMany(CardOracleTag::class, 'oracle_id', 'oracle_id');
    }

    public function getRouteKeyName(): string
    {
        return 'oracle_id';
    }
}
