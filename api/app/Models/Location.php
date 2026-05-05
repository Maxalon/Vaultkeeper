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
     * Locations the user manages directly as physical card storage. Excludes
     * the auto-managed `deck` (the per-deck shadow) and `pending_relocation`
     * (the singleton shrink bucket). Used by dropdowns and other surfaces
     * where a deck shadow is not a valid move target.
     */
    public function scopeUserManaged($query)
    {
        return $query->where('role', self::ROLE_USER);
    }

    /**
     * Rows that participate in the sidebar tree: regular user locations and
     * deck shadows. Pending relocation stays out — it lives in its own
     * sidebar slot above the sortable area.
     */
    public function scopeSidebarVisible($query)
    {
        return $query->whereIn('role', [self::ROLE_USER, self::ROLE_DECK]);
    }

    /**
     * Next sort_order for a brand-new top-level item. Top-level groups
     * (parent_group_id IS NULL) and top-level sidebar locations (group_id
     * IS NULL, role IN user|deck) share one ordering space.
     */
    public static function nextTopLevelSortOrder(int $userId): int
    {
        $maxLoc = (int) self::query()
            ->where('user_id', $userId)
            ->whereNull('group_id')
            ->whereIn('role', [self::ROLE_USER, self::ROLE_DECK])
            ->max('sort_order');

        $maxGroup = (int) LocationGroup::query()
            ->where('user_id', $userId)
            ->whereNull('parent_group_id')
            ->max('sort_order');

        return max($maxLoc, $maxGroup) + 1;
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
