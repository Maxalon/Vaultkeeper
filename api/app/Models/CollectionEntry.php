<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionEntry extends Model
{
    protected $fillable = [
        'user_id',
        'scryfall_id',
        'location_id',
        'quantity',
        'condition',
        'foil',
        'notes',
    ];

    protected $casts = [
        'foil' => 'boolean',
        'quantity' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // Card lookup uses scryfall_id as both local and foreign key
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'scryfall_id', 'scryfall_id');
    }
}
