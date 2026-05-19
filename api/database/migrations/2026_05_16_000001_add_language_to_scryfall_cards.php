<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scryfall assigns a distinct scryfall_id per language printing — a
     * Japanese Llanowar Elves has a different UUID from the English one.
     * Pre-existing rows came from the English-only default_cards bulk
     * file; the column default backfills them to 'en' so import flows
     * keep resolving English by default.
     *
     * Stored as Scryfall's `lang` field verbatim (en, ja, ko, ru, zhs,
     * zht, pt, es, fr, de, it, ph, qya, sa). Width 8 leaves slack for
     * any future codes.
     */
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->string('language', 8)->default('en')->after('collector_number');
            $table->index(['language', 'set_code']);
        });
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropIndex(['language', 'set_code']);
            $table->dropColumn('language');
        });
    }
};
