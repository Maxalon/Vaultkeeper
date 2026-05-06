<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Long-format daily price history — one row per (scryfall_id, captured_on,
 * finish) triple. Written only when a finish's price changes vs. the most
 * recent prior row, so unchanged prices don't bloat the table.
 *
 * 90-day retention; older rows are pruned at the end of each daily sync.
 */
class CardPriceHistory extends Model
{
    protected $table = 'card_price_history';
    public $timestamps = false;

    protected $fillable = [
        'scryfall_id',
        'captured_on',
        'finish',
        'price',
    ];

    protected $casts = [
        'captured_on' => 'date',
        'price'       => 'decimal:2',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'scryfall_id', 'scryfall_id');
    }
}
