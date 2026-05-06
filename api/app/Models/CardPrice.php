<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest EUR price snapshot for a single Scryfall printing. One row per
 * scryfall_id; the daily ScryfallSyncPricesJob upserts every printing
 * the bulk feed exposes a price for.
 *
 * Source: Scryfall's `prices.eur` / `prices.eur_foil` / `prices.eur_etched`
 * fields (Cardmarket trend prices). Any of the three columns can be NULL
 * when Cardmarket has no listing for that finish — the frontend renders
 * NULL as `—`.
 */
class CardPrice extends Model
{
    protected $table = 'card_prices';
    protected $primaryKey = 'scryfall_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'scryfall_id',
        'eur',
        'eur_foil',
        'eur_etched',
        'captured_on',
        'updated_at',
    ];

    protected $casts = [
        'eur'         => 'decimal:2',
        'eur_foil'    => 'decimal:2',
        'eur_etched'  => 'decimal:2',
        'captured_on' => 'date',
        'updated_at'  => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'scryfall_id', 'scryfall_id');
    }
}
