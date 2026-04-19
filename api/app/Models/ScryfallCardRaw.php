<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw Scryfall fields kept out of the main scryfall_cards row. Populated
 * alongside the main card during BulkSyncService::syncBulkCards. Currently
 * stores `all_parts` (tokens, combo pieces, meld pairs) used by deckbuilder
 * UI hints like "you picked Pir — suggest Toothy".
 */
class ScryfallCardRaw extends Model
{
    use HasFactory;

    protected $table = 'scryfall_cards_raw';

    protected $fillable = [
        'scryfall_id',
        'all_parts',
    ];

    protected $casts = [
        'all_parts' => 'array',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'scryfall_id', 'scryfall_id');
    }
}
