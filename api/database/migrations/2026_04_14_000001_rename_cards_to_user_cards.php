<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the FKs that reference cards.scryfall_id before renaming the
        // table — MySQL won't let us rename a table that has incoming FK
        // constraints in place.
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::rename('cards', 'user_cards');

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('user_cards');
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('user_cards');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::rename('user_cards', 'cards');

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('cards');
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('cards');
        });
    }
};
