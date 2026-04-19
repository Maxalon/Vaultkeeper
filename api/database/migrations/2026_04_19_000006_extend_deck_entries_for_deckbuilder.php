<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deckbuilder extension for deck_entries:
 *   - zone enum (main/side/maybe) replaces the is_sideboard boolean.
 *     Backfilled from is_sideboard before the old column is dropped.
 *   - category: user-visible bucket (ramp, removal, …). Set either by
 *     auto-assignment from card_oracle_tags or manually by the user.
 *   - is_commander: view-convenience flag mirroring decks.commander_*;
 *     controllers keep the two in sync, it is not independently writable.
 *   - is_signature_spell / signature_for_entry_id: Oathbreaker spells
 *     belonging to a specific planeswalker entry. Self-FK; no cascade —
 *     if the oathbreaker entry is deleted, the signature spell becomes
 *     an orphan that the legality engine flags (never auto-mutated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->enum('zone', ['main', 'side', 'maybe'])->default('main')->after('quantity');
            $table->string('category', 100)->nullable()->after('zone');
            $table->boolean('is_commander')->default(false)->after('category');
            $table->boolean('is_signature_spell')->default(false)->after('is_commander');
            $table->foreignId('signature_for_entry_id')->nullable()
                ->constrained('deck_entries')->nullOnDelete()
                ->after('is_signature_spell');
        });

        // Backfill zone from is_sideboard, then drop the old column.
        DB::statement("UPDATE deck_entries SET zone = CASE WHEN is_sideboard = 1 THEN 'side' ELSE 'main' END");

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn('is_sideboard');
        });
    }

    public function down(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->boolean('is_sideboard')->default(false)->after('quantity');
        });

        DB::statement("UPDATE deck_entries SET is_sideboard = CASE WHEN zone = 'side' THEN 1 ELSE 0 END");

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropForeign(['signature_for_entry_id']);
            $table->dropColumn([
                'zone',
                'category',
                'is_commander',
                'is_signature_spell',
                'signature_for_entry_id',
            ]);
        });
    }
};
