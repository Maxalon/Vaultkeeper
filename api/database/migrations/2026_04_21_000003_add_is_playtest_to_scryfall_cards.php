<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-card `is_playtest` flag, derived at bulk sync from Scryfall's
 * promo_types array (the `playtest` entry identifies cards printed as
 * playtest-style product — the DRC / Gen Con convention cards, later
 * collected into Mystery Booster 2's #501-#602 range and the CMB1/CMB2
 * Mystery Booster Playtest Cards sets).
 *
 * Previously we special-cased cmb1/cmb2 set codes, which missed the mb2
 * playtest subset entirely (mb2 has set_type='masters', so neither the
 * set_type nor set_code heuristic caught it). This column captures
 * Scryfall's canonical signal.
 *
 * Seeds with `true` for cmb1/cmb2 so the filter keeps working
 * immediately after deploy; the next scryfall:sync-bulk run overwrites
 * all rows with their per-card promo_types value, catching mb2 #501-#602
 * as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->boolean('is_playtest')
                  ->default(false)
                  ->after('is_default_eligible');
            $table->index('is_playtest');
        });

        DB::update(
            "UPDATE scryfall_cards SET is_playtest = 1 WHERE set_code IN ('cmb1', 'cmb2')"
        );

        // MB2 has a playtest subset at collector_numbers 501-602. Scryfall
        // flags these via promo_types=['playtest']; post-sync the bulk
        // pipeline sets is_playtest for them from that signal. Until the
        // first post-migration sync runs we'd miss them, so seed the
        // known range directly.
        DB::update(
            "UPDATE scryfall_cards SET is_playtest = 1
             WHERE set_code = 'mb2'
               AND CAST(collector_number AS UNSIGNED) BETWEEN 501 AND 602"
        );
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropIndex(['is_playtest']);
            $table->dropColumn('is_playtest');
        });
    }
};
