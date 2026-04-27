<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            // Where the deck originally came from. Lets the importer detect
            // re-imports of the same source deck (skip or update flow) and
            // gives the UI somewhere to surface "Imported from Archidekt".
            $table->string('source', 24)->nullable()->after('description');
            $table->string('source_id', 64)->nullable()->after('source');

            // Per-user uniqueness on (source, source_id). NULLs are ignored
            // by MySQL's unique index so manually-created decks are
            // unaffected.
            $table->unique(['user_id', 'source', 'source_id'], 'decks_user_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropUnique('decks_user_source_unique');
            $table->dropColumn(['source', 'source_id']);
        });
    }
};
