<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companion table to `scryfall_cards` that holds raw Scryfall fields we
     * don't want bloating the main card row but still want access to —
     * starting with `all_parts` (combo_piece / token / meld links) used by
     * the deckbuilder's Partner-with suggestion UI. Future use cases (token
     * enumeration, combo piece lookups) can add columns here without
     * touching the hot card table.
     *
     * Self-healing: if scryfall_cards is already populated when this runs,
     * trigger scryfall:sync-bulk so the new raw table + the partner_scope /
     * commander_game_changer columns from migration 1 get populated in one
     * pass. Fresh installs with an empty scryfall_cards skip the sync —
     * the scheduled weekly sync-bulk will cover them when cards first land.
     */
    public function up(): void
    {
        Schema::create('scryfall_cards_raw', function (Blueprint $table) {
            $table->id();
            $table->uuid('scryfall_id')->unique();
            $table->json('all_parts')->nullable();
            $table->timestamps();

            $table->foreign('scryfall_id')
                ->references('scryfall_id')
                ->on('scryfall_cards')
                ->cascadeOnDelete();
        });

        if (DB::table('scryfall_cards')->exists()) {
            Log::info('create_scryfall_cards_raw migration: existing card data detected, running scryfall:sync-bulk to populate new columns/table');
            fwrite(STDERR, ">>> Existing scryfall_cards data detected; running scryfall:sync-bulk to populate new columns + raw table (may take several minutes)...\n");
            Artisan::call('scryfall:sync-bulk');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scryfall_cards_raw');
    }
};
