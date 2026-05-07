<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-printing daily price snapshot. EUR only — sourced from Scryfall's
 * bulk feed, which carries Cardmarket trend prices in `prices.eur` /
 * `prices.eur_foil` / `prices.eur_etched`.
 *
 * Kept separate from scryfall_cards so the daily writer doesn't churn
 * the wide ~100K-row catalog table — only this narrow row gets rewritten
 * each day. captured_on tracks the bulk-feed date the snapshot came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_prices', function (Blueprint $table) {
            $table->uuid('scryfall_id')->primary();
            $table->decimal('eur', 10, 2)->nullable();
            $table->decimal('eur_foil', 10, 2)->nullable();
            $table->decimal('eur_etched', 10, 2)->nullable();
            $table->date('captured_on');
            $table->timestamp('updated_at')->nullable();

            $table->index('captured_on');
            $table->foreign('scryfall_id')
                ->references('scryfall_id')
                ->on('scryfall_cards')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_prices');
    }
};
