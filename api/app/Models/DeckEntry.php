<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeckEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'deck_id',
        'scryfall_id',
        'quantity',
        'zone',
        'category',
        'is_commander',
        'is_signature_spell',
        'signature_for_entry_id',
        'wanted',
        'physical_copy_id',
        'needs_review',
    ];

    protected $casts = [
        'quantity'           => 'integer',
        'is_commander'       => 'boolean',
        'is_signature_spell' => 'boolean',
        'needs_review'       => 'boolean',
    ];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'scryfall_id', 'scryfall_id');
    }

    public function physicalCopy(): BelongsTo
    {
        return $this->belongsTo(CollectionEntry::class, 'physical_copy_id');
    }

    public function signatureFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'signature_for_entry_id');
    }

    public function signatureSpells(): HasMany
    {
        return $this->hasMany(self::class, 'signature_for_entry_id');
    }
}
