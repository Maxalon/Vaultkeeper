<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'format',
        'description',
        'is_archived',
        'commander_1_scryfall_id',
        'commander_2_scryfall_id',
        'color_identity',
        'group_id',
        'sort_order',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'sort_order'  => 'integer',
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(LocationGroup::class, 'group_id');
    }
}
