<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            // Wizards' Commander "game changer" flag — marks high-power cards
            // for optional format-level scrutiny. Per-printing boolean from
            // Scryfall's `game_changer` field.
            $table->boolean('commander_game_changer')->default(false)->after('reserved');

            // Derived partner variant, populated by BulkSyncService from the
            // "Partner—X" pattern in oracle_text. null = not a partner,
            // 'plain' = generic Partner (includes Partner with X),
            // other values = specific variant (friends_forever, survivors, …).
            // Cards pair only when partner_scope matches exactly (except
            // asymmetric pairings — Doctor's companion / Choose a background —
            // which live in DeckLegalityService).
            $table->string('partner_scope', 50)->nullable()->after('commander_game_changer');
        });
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropColumn(['commander_game_changer', 'partner_scope']);
        });
    }
};
