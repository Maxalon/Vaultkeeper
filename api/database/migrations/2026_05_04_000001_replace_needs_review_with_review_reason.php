<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the boolean `needs_review` flag (on collection_entries and
 * deck_entries) plus the `pending_relocation` Location bucket with a
 * single `review_reason` enum-string column on collection_entries.
 *
 * Three reasons today:
 *
 *   - 'no_location'             — CE has no location_id and needs the user to
 *                                 pick one. Replaces the pending bucket.
 *   - 'default_values_applied'  — CE was minted by assemble with default
 *                                 values (NM, non-foil); the user should
 *                                 confirm or correct.
 *   - 'card_data_changed'       — Scryfall deleted/migrated the underlying
 *                                 card; user should rebind or discard.
 *
 * Backfill rules (in order, each row gets exactly one reason):
 *
 *   1. CEs in a pending_relocation Location → location_id=NULL,
 *      review_reason='no_location'. source_deck_* preserved.
 *   2. CEs with needs_review=true AND location_id is a deck-location →
 *      review_reason='default_values_applied'. Location unchanged.
 *   3. Remaining needs_review=true CEs → review_reason='card_data_changed'.
 *      (BulkSyncService::markDeleted's case.)
 *
 * After backfill the now-empty pending Location rows are deleted, and the
 * `needs_review` columns are dropped from both tables. The
 * `pending_relocation` enum value on `locations.role` is left alive (no
 * rows reference it) — dropping it requires an ALTER COLUMN on every
 * MySQL row, not worth the cost vs. a dead enum value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->string('review_reason', 40)
                ->nullable()
                ->after('source_deck_deleted');
            $table->index('review_reason', 'collection_entries_review_reason_index');
        });

        DB::transaction(function () {
            // Rule 1: pending bucket → no_location.
            DB::table('collection_entries')
                ->whereIn('location_id', function ($q) {
                    $q->select('id')
                        ->from('locations')
                        ->where('role', 'pending_relocation');
                })
                ->update([
                    'location_id'   => null,
                    'review_reason' => 'no_location',
                ]);

            // Rule 2: deck-location + needs_review=true → default_values_applied.
            DB::table('collection_entries')
                ->where('needs_review', true)
                ->whereNull('review_reason')
                ->whereIn('location_id', function ($q) {
                    $q->select('id')
                        ->from('locations')
                        ->where('role', 'deck');
                })
                ->update(['review_reason' => 'default_values_applied']);

            // Rule 3: remaining needs_review=true → card_data_changed.
            DB::table('collection_entries')
                ->where('needs_review', true)
                ->whereNull('review_reason')
                ->update(['review_reason' => 'card_data_changed']);
        });

        // Now that every CE has migrated off the bucket Locations, drop them.
        DB::table('locations')
            ->where('role', 'pending_relocation')
            ->delete();

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });

        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('notes');
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('wanted');
        });

        // Best-effort flip — restoring exact pre-state isn't possible
        // (we lost the location_id of pending CEs).
        DB::table('collection_entries')
            ->whereNotNull('review_reason')
            ->where('review_reason', '!=', 'no_location')
            ->update(['needs_review' => true]);

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropIndex('collection_entries_review_reason_index');
            $table->dropColumn('review_reason');
        });
    }
};
