<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    public const ROLE_USER = 'user';
    public const ROLE_DECK = 'deck';
    public const ROLE_PENDING_RELOCATION = 'pending_relocation';

    protected $fillable = [
        'user_id',
        'group_id',
        'deck_id',
        'role',
        'type',
        'name',
        'set_codes',
        'description',
        'sort_order',
    ];

    protected $attributes = [
        'role' => self::ROLE_USER,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LocationGroup::class, 'group_id');
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }

    /**
     * Locations the user manages directly (drawers, binders, user-created
     * deck-themed shelves). Excludes the auto-managed `deck` and
     * `pending_relocation` rows that aren't surfaced in the sidebar.
     */
    public function scopeUserManaged($query)
    {
        return $query->where('role', self::ROLE_USER);
    }

    /**
     * Recompute set_codes from the distinct card set codes present in this location.
     * Stores as a sorted, comma-separated uppercase string (e.g. "FDN,MKM,TLA,TLE").
     */
    public function refreshSetCodes(): void
    {
        $codes = $this->entries()
            ->join('scryfall_cards', 'collection_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
            ->whereNotNull('scryfall_cards.set_code')
            ->where('scryfall_cards.set_code', '!=', '')
            ->distinct()
            ->pluck('scryfall_cards.set_code')
            ->map(fn (string $c) => strtoupper($c))
            ->sort()
            ->values()
            ->implode(',');

        $this->update(['set_codes' => $codes ?: null]);
    }
}
