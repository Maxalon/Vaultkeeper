<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-deck-slot finish, used only when the entry is unbound (no
 * physical_copy_id). Bound entries always read finish from the linked
 * CollectionEntry; these columns are ignored in that case.
 *
 * Both nullable with default NULL so existing rows survive without a
 * backfill. NULL is treated as "unspecified — render as nonfoil" at
 * presentation time, which lets us distinguish pre-feature rows from
 * rows where the user actively picked nonfoil should we ever want to.
 *
 * Mirrors the (foil, is_etched) shape on collection_entries (where
 * is_etched was added by the pricing pipeline) so DeckOwnershipService
 * can join through identical column names. Mutual exclusion
 * (is_etched=true forces foil=false) is enforced in the controller, not
 * at the DB level — same pattern as PhysicalCopyEditService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->boolean('foil')->nullable()->default(null)->after('physical_copy_id');
            $table->boolean('is_etched')->nullable()->default(null)->after('foil');
        });
    }

    public function down(): void
    {
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn(['foil', 'is_etched']);
        });
    }
};
