<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trail for the Pending Relocation bucket. When a user shrinks/removes a
 * deck_entry whose linked copy lives in that deck's deck-location, the copy
 * is moved to the user's pending location and tagged with the source deck so
 * the UI can render "from <deck name>" labels. Snapshot keeps the label alive
 * after the deck itself is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->foreignId('source_deck_id')
                ->nullable()
                ->after('location_id')
                ->constrained('decks')
                ->nullOnDelete();

            $table->string('source_deck_name_snapshot', 100)
                ->nullable()
                ->after('source_deck_id');

            $table->boolean('source_deck_deleted')
                ->default(false)
                ->after('source_deck_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_deck_id');
            $table->dropColumn(['source_deck_name_snapshot', 'source_deck_deleted']);
        });
    }
};
