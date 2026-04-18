<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MTG set metadata, mirrored from Scryfall's /sets endpoint.
 *
 * Class is `MtgSet` rather than `Set` because `Set` is reserved-adjacent
 * in PHP (clashes with common stdlib/Symfony types). Table is `sets`.
 */
class MtgSet extends Model
{
    use HasFactory;

    protected $table = 'sets';

    protected $fillable = [
        'scryfall_id',
        'code',
        'name',
        'set_type',
        'released_at',
        'card_count',
        'our_card_count',
        'icon_svg_uri',
        'search_uri',
        'last_synced_at',
    ];

    protected $casts = [
        'released_at'    => 'date',
        'card_count'     => 'integer',
        'our_card_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function cards(): HasMany
    {
        return $this->hasMany(ScryfallCard::class, 'set_code', 'code');
    }
}
