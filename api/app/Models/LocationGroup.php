<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationGroup extends Model
{
    protected $fillable = [
        'user_id',
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

    /**
     * Next sort_order for a brand-new top-level item. Groups and top-level
     * (group_id IS NULL) locations share one ordering space, so we take the
     * max across both and add one.
     */
    public static function nextTopLevelSortOrder(int $userId): int
    {
        $maxGroup = (int) self::where('user_id', $userId)->max('sort_order');
        $maxLoc   = (int) Location::where('user_id', $userId)
            ->whereNull('group_id')
            ->max('sort_order');

        return max($maxGroup, $maxLoc) + 1;
    }
}
