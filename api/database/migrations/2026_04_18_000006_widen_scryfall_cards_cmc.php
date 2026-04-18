<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un-set joke cards (e.g. Gleemax from Unhinged) carry CMCs as high
        // as 1,000,000, which overflows decimal(8,2). decimal(12,2) gives
        // headroom up to ~10 billion — comfortably beyond anything Scryfall
        // would emit.
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->decimal('cmc', 12, 2)->nullable()->change();
        });

        // Wipe any partial sync state so the next scryfall:sync-bulk run
        // re-populates from scratch with the wider column.
        DB::table('scryfall_cards')->truncate();
        DB::table('sets')->update(['our_card_count' => 0]);
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->decimal('cmc', 8, 2)->nullable()->change();
        });
    }
};
