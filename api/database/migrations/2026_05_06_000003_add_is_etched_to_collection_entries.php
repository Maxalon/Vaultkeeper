<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `is_etched` to collection_entries. Etched cards are a third finish
 * flavour alongside nonfoil/foil — they price separately on Cardmarket
 * (eur_etched) and need their own picker so a user can record an etched
 * copy without losing the printing's nonfoil/foil price tracks.
 *
 * Mutually exclusive with `foil` — the controller enforces that
 * `is_etched=true` implies `foil=false`. Not enforced at the DB level so
 * the legacy data path continues to work; UI tri-state selector is the
 * canonical way to set this field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->boolean('is_etched')->default(false)->after('foil');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropColumn('is_etched');
        });
    }
};
