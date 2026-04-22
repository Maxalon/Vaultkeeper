<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical Scryfall card reference. One row per English printing,
 * synced from the Default Cards bulk file by BulkSyncService.
 *
 * collection_entries.scryfall_id and deck_entries.scryfall_id FK here.
 */
class ScryfallCard extends Model
{
    use HasFactory;

    protected $table = 'scryfall_cards';

    protected $fillable = [
        'scryfall_id',
        'oracle_id',
        'name',
        'set_code',
        'collector_number',
        'rarity',
        'layout',
        'is_dfc',
        'mana_cost',
        'cmc',
        'colors',
        'color_identity',
        'produced_mana',
        'type_line',
        'oracle_text',
        'power',
        'toughness',
        'loyalty',
        'legalities',
        'keywords',
        'image_small',
        'image_normal',
        'image_large',
        'image_small_back',
        'image_normal_back',
        'image_large_back',
        'mana_cost_back',
        'type_line_back',
        'oracle_text_back',
        'edhrec_rank',
        'reserved',
        'commander_game_changer',
        'partner_scope',
        'supertypes',
        'types',
        'subtypes',
        'released_at',
        'promo',
        'variation',
        'set_type',
        'oversized',
        'is_default_eligible',
        'is_playtest',
        'printed_text',
        'printed_text_back',
        'last_synced_at',
    ];

    protected $casts = [
        'is_dfc'                 => 'boolean',
        'reserved'               => 'boolean',
        'commander_game_changer' => 'boolean',
        'promo'                  => 'boolean',
        'variation'              => 'boolean',
        'oversized'              => 'boolean',
        'is_default_eligible'    => 'boolean',
        'is_playtest'            => 'boolean',
        'colors'                 => 'array',
        'color_identity'         => 'array',
        'produced_mana'          => 'array',
        'legalities'             => 'array',
        'keywords'               => 'array',
        'supertypes'             => 'array',
        'types'                  => 'array',
        'subtypes'               => 'array',
        'cmc'                    => 'decimal:2',
        'edhrec_rank'            => 'integer',
        'released_at'            => 'date',
        'last_synced_at'         => 'datetime',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(MtgSet::class, 'set_code', 'code');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CardOracleTag::class, 'oracle_id', 'oracle_id');
    }

    public function raw(): HasOne
    {
        return $this->hasOne(ScryfallCardRaw::class, 'scryfall_id', 'scryfall_id');
    }

    public function getRouteKeyName(): string
    {
        // /api/scryfall-cards/{scryfallCard} resolves by scryfall_id (UUID),
        // not the surrogate auto-increment id.
        return 'scryfall_id';
    }
}
