<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert `deck_entries.wanted` from boolean to nullable zone enum so the
 * frontend can show which zone a deck wants the card in (main colour-codes
 * differently from side/maybe). MySQL can't MODIFY a boolean directly into
 * a varchar enum, so we add a parallel column, migrate values, then swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->enum('wanted_new', ['main', 'side', 'maybe'])->nullable()->after('wanted');
        });

        DB::statement("UPDATE deck_entries SET wanted_new = 'main' WHERE wanted = 1");

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn('wanted');
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->renameColumn('wanted_new', 'wanted');
        });
    }

    public function down(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->boolean('wanted_bool')->default(false)->after('wanted');
        });

        DB::statement('UPDATE deck_entries SET wanted_bool = CASE WHEN wanted IS NULL THEN 0 ELSE 1 END');

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn('wanted');
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->renameColumn('wanted_bool', 'wanted');
        });
    }
};
