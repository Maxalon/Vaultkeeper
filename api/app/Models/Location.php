<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'type',
        'name',
        'set_codes',
        'description',
        'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LocationGroup::class, 'group_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }

    /**
     * Recompute set_codes from the distinct card set codes present in this location.
     * Stores as a sorted, comma-separated uppercase string (e.g. "FDN,MKM,TLA,TLE").
     */
    public function refreshSetCodes(): void
    {
        $codes = $this->entries()
            ->join('user_cards', 'collection_entries.scryfall_id', '=', 'user_cards.scryfall_id')
            ->whereNotNull('user_cards.set_code')
            ->where('user_cards.set_code', '!=', '')
            ->distinct()
            ->pluck('user_cards.set_code')
            ->map(fn (string $c) => strtoupper($c))
            ->sort()
            ->values()
            ->implode(',');

        $this->update(['set_codes' => $codes ?: null]);
    }
}
