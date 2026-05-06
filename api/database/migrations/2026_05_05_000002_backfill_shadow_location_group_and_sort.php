<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: copy each deck's `group_id` and `sort_order` onto its shadow
 * Location row (role='deck', deck_id matches). After this runs, the shadow
 * row is the canonical sortable entity for the sidebar; the next migration
 * drops the now-redundant columns from `decks`.
 *
 * Idempotent: running twice produces the same result.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('decks')
            ->select('id', 'group_id', 'sort_order')
            ->orderBy('id')
            ->chunkById(500, function ($decks) {
                foreach ($decks as $deck) {
                    DB::table('locations')
                        ->where('deck_id', $deck->id)
                        ->where('role', 'deck')
                        ->update([
                            'group_id'   => $deck->group_id,
                            'sort_order' => (int) $deck->sort_order,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No-op: the source columns on decks still exist at this point in the
        // migration history. If the next migration (which drops them) is
        // rolled back too, re-running this backfill restores the values.
    }
};
