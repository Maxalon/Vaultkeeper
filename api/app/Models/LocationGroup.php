<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationGroup extends Model
{
    protected $fillable = [
        'user_id',
        'parent_group_id',
        'name',
        'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_group_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_group_id');
    }

    /**
     * Next sort_order for a top-level item. Top-level groups
     * (parent_group_id IS NULL) and top-level sidebar locations
     * (group_id IS NULL, role IN user|deck) share one ordering space.
     */
    public static function nextTopLevelSortOrder(int $userId): int
    {
        return Location::nextTopLevelSortOrder($userId);
    }

    /**
     * Next sort_order for a new child of `$parentGroupId`. Child groups
     * (parent_group_id = X) and child locations (group_id = X) share one
     * ordering space.
     */
    public static function nextChildSortOrder(int $userId, int $parentGroupId): int
    {
        $maxLoc = (int) Location::query()
            ->where('user_id', $userId)
            ->where('group_id', $parentGroupId)
            ->whereIn('role', [Location::ROLE_USER, Location::ROLE_DECK])
            ->max('sort_order');

        $maxGroup = (int) self::query()
            ->where('user_id', $userId)
            ->where('parent_group_id', $parentGroupId)
            ->max('sort_order');

        return max($maxLoc, $maxGroup) + 1;
    }

    /**
     * IDs of every descendant of this group (children, grandchildren, ...).
     * Used for cycle prevention when reparenting and for "exclude me + my
     * subtree" filters in the parent-picker UI.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $stack = [$this->id];
        while ($stack !== []) {
            $current = array_pop($stack);
            $children = self::query()
                ->where('parent_group_id', $current)
                ->pluck('id')
                ->all();
            foreach ($children as $childId) {
                $ids[] = $childId;
                $stack[] = $childId;
            }
        }

        return $ids;
    }
}
