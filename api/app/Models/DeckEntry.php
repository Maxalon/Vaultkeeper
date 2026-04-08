<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckEntry extends Model
{
    protected $fillable = [
        'deck_id',
        'scryfall_id',
        'quantity',
        'is_sideboard',
        'wanted',
        'physical_copy_id',
    ];

    protected $casts = [
        'is_sideboard' => 'boolean',
        'wanted' => 'boolean',
        'quantity' => 'integer',
    ];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'scryfall_id', 'scryfall_id');
    }

    public function physicalCopy(): BelongsTo
    {
        return $this->belongsTo(CollectionEntry::class, 'physical_copy_id');
    }
}
