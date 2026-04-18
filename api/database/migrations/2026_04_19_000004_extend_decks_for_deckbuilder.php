<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the deckbuilder columns to `decks`:
 *   - commander_1_scryfall_id / commander_2_scryfall_id: authoritative
 *     commander slots (FK → scryfall_cards, SET NULL on delete so the
 *     deck survives a Scryfall card removal with the slot cleared).
 *   - color_identity: cached WUBRG string derived from commanders, updated
 *     by controllers whenever commanders change.
 *   - group_id / sort_order: mirror the locations sidebar grouping pattern
 *     so decks can live alongside locations in LocationGroupController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->uuid('commander_1_scryfall_id')->nullable()->after('format');
            $table->uuid('commander_2_scryfall_id')->nullable()->after('commander_1_scryfall_id');
            $table->string('color_identity', 5)->nullable()->after('commander_2_scryfall_id');

            $table->foreignId('group_id')->nullable()
                ->constrained('location_groups')
                ->nullOnDelete()
                ->after('is_archived');
            $table->integer('sort_order')->default(0)->after('group_id');

            $table->foreign('commander_1_scryfall_id')
                ->references('scryfall_id')->on('scryfall_cards')
                ->nullOnDelete()
                ->onUpdate('cascade');
            $table->foreign('commander_2_scryfall_id')
                ->references('scryfall_id')->on('scryfall_cards')
                ->nullOnDelete()
                ->onUpdate('cascade');

            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropForeign(['commander_1_scryfall_id']);
            $table->dropForeign(['commander_2_scryfall_id']);
            $table->dropForeign(['group_id']);
            $table->dropIndex(['user_id', 'sort_order']);
            $table->dropColumn([
                'commander_1_scryfall_id',
                'commander_2_scryfall_id',
                'color_identity',
                'group_id',
                'sort_order',
            ]);
        });
    }
};
