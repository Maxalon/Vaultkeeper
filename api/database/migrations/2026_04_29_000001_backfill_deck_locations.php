<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: every existing deck gets the auto-managed Location row
 * that DeckObserver creates for new decks. Branch-2 changes assume this
 * row exists; branch-3's shrink-to-pending logic depends on it. Runs raw
 * SQL (no model events) so the observer doesn't double-fire on any decks
 * created via deck factories during the migration window.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('decks')
            ->select('id', 'user_id', 'name')
            ->orderBy('id')
            ->chunkById(500, function ($decks) {
                $existing = DB::table('locations')
                    ->whereIn('deck_id', $decks->pluck('id'))
                    ->where('role', 'deck')
                    ->pluck('deck_id')
                    ->all();

                $rows = [];
                $now = now();
                foreach ($decks as $deck) {
                    if (in_array($deck->id, $existing, true)) {
                        continue;
                    }
                    $name = 'Deck: ' . mb_substr($deck->name, 0, 100 - strlen('Deck: '));
                    $rows[] = [
                        'user_id'    => $deck->user_id,
                        'deck_id'    => $deck->id,
                        'role'       => 'deck',
                        // type is enum('drawer','binder'); role disambiguates.
                        'type'       => 'drawer',
                        'name'       => $name,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table('locations')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Removing the backfilled rows isn't safe in isolation — by the time
        // a rollback runs, those rows may own collection_entries via
        // location_id. Leave them in place; the schema rollback in
        // 2026_04_28_000002 drops the deck_id/role columns entirely.
    }
};
