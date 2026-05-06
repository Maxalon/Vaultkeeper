<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deck extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'format',
        'description',
        'source',
        'source_id',
        'is_archived',
        'commander_1_scryfall_id',
        'commander_2_scryfall_id',
        'companion_scryfall_id',
        'color_identity',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DeckEntry::class);
    }

    public function ignoredIllegalities(): HasMany
    {
        return $this->hasMany(DeckIgnoredIllegality::class);
    }

    public function commander1(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'commander_1_scryfall_id', 'scryfall_id');
    }

    public function commander2(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'commander_2_scryfall_id', 'scryfall_id');
    }

    public function companion(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'companion_scryfall_id', 'scryfall_id');
    }

    /**
     * The auto-managed Location row that physically backs this deck and
     * carries its sidebar position. Created by DeckObserver on deck create,
     * renamed on deck rename, removed via FK cascade on deck delete.
     */
    public function deckLocation(): HasOne
    {
        return $this->hasOne(Location::class)->where('role', Location::ROLE_DECK);
    }
}
