<?php

use App\Services\BulkSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persist Scryfall's per-set `digital` flag so the printing picker (and
 * any other catalog surface) can hide MTG-Arena/MTGO-only sets like
 * Pioneer Masters (PIO) and Alchemy releases. Per-card `games` array
 * filtering already runs at intake (BulkSyncService::applyBulkCardData
 * line 483), but the printings endpoint joins to `sets` for set-name
 * and icon, and currently has no way to tell which sets are digital
 * because the column didn't exist. This adds it and self-heals by
 * calling BulkSyncService::syncSets() to backfill from Scryfall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->boolean('digital')->default(false)->after('set_type');
        });

        // Self-heal: existing rows have digital=false until the next /sets
        // sync runs. Trigger one now so the column is populated immediately,
        // mirroring the pattern in 2026_04_19_000002. Refreshing /sets is
        // a single Scryfall API call (~600 rows) — much cheaper than a full
        // bulk-cards sync, so safe to run inline.
        if (DB::table('sets')->exists()) {
            Log::info('add_digital_to_sets migration: existing sets data detected, refreshing from Scryfall to populate digital flag');
            fwrite(STDERR, ">>> Existing sets data detected; refreshing from Scryfall to populate digital flag...\n");
            try {
                /** @var BulkSyncService $bulk */
                $bulk = app(BulkSyncService::class);
                $bulk->syncSets();
            } catch (Throwable $e) {
                // Don't block deploys on a transient Scryfall hiccup; the
                // weekly scheduled sync will populate digital on its own.
                Log::warning('add_digital_to_sets self-heal failed: ' . $e->getMessage());
                fwrite(STDERR, ">>> Self-heal failed: {$e->getMessage()}. Run `php artisan scryfall:check-sets` manually.\n");
            }
        }
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('digital');
        });
    }
};

