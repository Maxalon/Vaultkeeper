<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `companion_scryfall_id` FK to `decks` mirroring the commander
 * pattern. Player explicitly picks the companion card; the card must also
 * be present as a deck_entries row (validated by DeckLegalityService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->uuid('companion_scryfall_id')
                ->nullable()
                ->after('commander_2_scryfall_id');

            $table->foreign('companion_scryfall_id')
                ->references('scryfall_id')->on('scryfall_cards')
                ->nullOnDelete()
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropForeign(['companion_scryfall_id']);
            $table->dropColumn('companion_scryfall_id');
        });
    }
};
