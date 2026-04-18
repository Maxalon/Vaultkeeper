<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckIgnoredIllegality extends Model
{
    use HasFactory;

    protected $fillable = [
        'deck_id',
        'illegality_type',
        'scryfall_id_1',
        'scryfall_id_2',
        'oracle_id',
        'expected_count',
    ];

    protected $casts = [
        'expected_count' => 'integer',
    ];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }
}
