<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist Scryfall's `finishes` array on each printing so the UI can tell
 * "this printing has no foil version" (e.g. Alpha) apart from "user happens
 * not to own a foil copy". Values come straight from Scryfall and are drawn
 * from {nonfoil, foil, etched, glossy}.
 *
 * Nullable so existing rows survive without a backfill; populated
 * incrementally on the next BulkSyncService run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->json('finishes')->nullable()->after('keywords');
        });
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropColumn('finishes');
        });
    }
};
