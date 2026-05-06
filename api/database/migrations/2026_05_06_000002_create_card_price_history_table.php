<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-format price history. One row per (scryfall_id, captured_on, finish)
 * triple, written only when the price for that finish actually changes vs.
 * the most recent history row. 90-day retention; the daily price sync
 * prunes anything older.
 *
 * `finish` is the printing's finish flavour: nonfoil / foil / etched.
 * EUR prices only — TCGPlayer/USD is intentionally unsupported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_price_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('scryfall_id');
            $table->date('captured_on');
            $table->enum('finish', ['nonfoil', 'foil', 'etched']);
            $table->decimal('price', 10, 2);

            $table->unique(['scryfall_id', 'captured_on', 'finish'], 'card_price_history_unique');
            $table->index('captured_on');
            $table->index(['scryfall_id', 'captured_on']);

            $table->foreign('scryfall_id')
                ->references('scryfall_id')
                ->on('scryfall_cards')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_price_history');
    }
};
