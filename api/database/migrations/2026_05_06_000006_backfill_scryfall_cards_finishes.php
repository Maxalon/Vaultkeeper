<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill scryfall_cards.finishes for existing rows. The column was
 * added (nullable) in 2026_05_06_000004 but BulkSyncService only
 * writes it on the next bulk sync, leaving every pre-existing row at
 * NULL — which the deck-builder's finish selector reads as "unknown"
 * and would (briefly) treat as "all finishes allowed", surfacing
 * Etched on cards that don't actually have an etched printing.
 *
 * Self-heals the same way 2026_04_19_000002 does for the raw-table
 * additions: if there are scryfall_cards rows whose finishes is still
 * NULL, fire scryfall:sync-bulk so the next deploy/dev session has a
 * correctly-populated finishes column without manual intervention.
 *
 * Fresh installs with an empty scryfall_cards skip the sync — the
 * scheduled weekly sync-bulk will populate finishes when cards land.
 */
return new class extends Migration
{
    public function up(): void
    {
        $needsSync = DB::table('scryfall_cards')
            ->whereNull('finishes')
            ->limit(1)
            ->exists();

        if (! $needsSync) {
            return;
        }

        Log::info('backfill_finishes migration: NULL finishes detected, running scryfall:sync-bulk');
        fwrite(STDERR, ">>> Existing scryfall_cards rows have NULL finishes; running scryfall:sync-bulk to backfill (may take several minutes)...\n");
        Artisan::call('scryfall:sync-bulk');
    }

    public function down(): void
    {
        // No-op: nulling out finishes on rollback would leave the column
        // worse than the post-up state. The original 2026_05_06_000004
        // migration's down() drops the column entirely if needed.
    }
};
