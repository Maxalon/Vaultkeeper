<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical Scryfall card reference. One row per English printing,
 * synced from the Default Cards bulk file.
 *
 * Distinct from UserCard, which only contains cards a user has imported.
 * Both share `scryfall_id` as a join key but have no FK relationship —
 * a card can exist in either table without the other.
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
        'last_synced_at',
    ];

    protected $casts = [
        'is_dfc'         => 'boolean',
        'reserved'       => 'boolean',
        'colors'         => 'array',
        'color_identity' => 'array',
        'legalities'     => 'array',
        'keywords'       => 'array',
        'cmc'            => 'decimal:2',
        'edhrec_rank'    => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(MtgSet::class, 'set_code', 'code');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CardOracleTag::class, 'oracle_id', 'oracle_id');
    }

    public function getRouteKeyName(): string
    {
        // /api/scryfall-cards/{scryfallCard} resolves by scryfall_id (UUID),
        // not the surrogate auto-increment id.
        return 'scryfall_id';
    }
}
