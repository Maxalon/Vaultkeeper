<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set true by BulkSyncService::handleMigrations() when Scryfall
        // deletes the underlying card. The collection UI surfaces these
        // for user review (replace, delete, etc.) in a follow-up session.
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('notes');
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('wanted');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};
