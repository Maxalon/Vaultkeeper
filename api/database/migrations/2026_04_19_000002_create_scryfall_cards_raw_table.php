<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('scryfall_cards_raw');
    }
};
