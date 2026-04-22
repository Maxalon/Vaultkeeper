<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `produced_mana` JSON column populated from Scryfall's `produced_mana`
 * field. Used by the deckbuilder Analysis tab to compute accurate mana
 * production percentages rather than heuristics from color_identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->json('produced_mana')->nullable()->after('color_identity');
        });
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropColumn('produced_mana');
        });
    }
};
