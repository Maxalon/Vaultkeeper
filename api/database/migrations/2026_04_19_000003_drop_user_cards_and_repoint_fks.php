<?php

use App\Services\BulkSyncService;
use App\Services\ScryfallService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the `user_cards` table. Every column in user_cards duplicates
 * scryfall_cards except `last_scryfall_sync` (an obsolete per-user sync
 * cache), so the table was redundant the moment scryfall_cards landed.
 *
 * Repoints FK columns that used to reference user_cards (collection_entries
 * and deck_entries both keyed by scryfall_id) at scryfall_cards instead.
 *
 * Self-healing pre-flight: if any collection_entries / deck_entries row
 * references a scryfall_id not in scryfall_cards, the migration runs
 * `scryfall:sync-bulk` in-place to populate it. Only if orphans remain
 * after the sync does the migration abort.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureScryfallCardsCoverage();

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::dropIfExists('user_cards');

        // Re-point FKs at scryfall_cards. ON UPDATE CASCADE so Scryfall's
        // scryfall_id rename migrations flow through automatically.
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')
                ->references('scryfall_id')
                ->on('scryfall_cards')
                ->onUpdate('cascade');
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')
                ->references('scryfall_id')
                ->on('scryfall_cards')
                ->onUpdate('cascade');
        });
    }

    /**
     * Rollback recreates user_cards as an empty stub so downstream FKs and
     * legacy code paths can re-bind, but does NOT attempt to repopulate
     * card data — data must be restored from a backup or re-imported.
     */
    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->dropForeign(['scryfall_id']);
        });

        Schema::create('user_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('scryfall_id')->unique();
            $table->string('name')->nullable();
            $table->string('set_code')->nullable();
            $table->string('collector_number')->nullable();
            $table->string('rarity')->nullable();
            $table->boolean('is_dfc')->default(false);
            $table->string('mana_cost')->nullable();
            $table->text('oracle_text')->nullable();
            $table->string('type_line')->nullable();
            $table->string('power')->nullable();
            $table->string('toughness')->nullable();
            $table->string('loyalty')->nullable();
            $table->json('legalities')->nullable();
            $table->json('colors')->nullable();
            $table->string('image_small')->nullable();
            $table->string('image_normal')->nullable();
            $table->string('image_large')->nullable();
            $table->string('image_small_back')->nullable();
            $table->string('image_normal_back')->nullable();
            $table->string('image_large_back')->nullable();
            $table->string('mana_cost_back')->nullable();
            $table->string('type_line_back')->nullable();
            $table->text('oracle_text_back')->nullable();
            $table->timestamp('last_scryfall_sync')->nullable();
            $table->timestamps();
        });

        Schema::table('collection_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('user_cards');
        });
        Schema::table('deck_entries', function (Blueprint $table) {
            $table->foreign('scryfall_id')->references('scryfall_id')->on('user_cards');
        });
    }

    /**
     * Guarantee scryfall_cards contains every scryfall_id the to-be-repointed
     * FKs will reference. Three-stage self-healing:
     *
     *   1. If no orphans, skip entirely (fast path on already-in-sync envs).
     *   2. Run `scryfall:sync-bulk` (populates ~114k English printings).
     *   3. For any orphan still remaining, hit Scryfall's /cards/{id}
     *      endpoint one-by-one and upsert the response. This catches
     *      non-English printings, special variants, and anything else
     *      outside the English default_cards bulk file.
     *
     * Only if orphans remain after all three stages does the migration
     * abort — at that point the referenced scryfall_id is either fabricated
     * or has been removed from Scryfall entirely.
     */
    private function ensureScryfallCardsCoverage(): void
    {
        if ($this->countOrphans() === 0) {
            return;
        }

        $before = $this->countOrphans();

        // Skip sync-bulk on retry if it already ran within the last hour
        // (migration 2 runs it, and if this migration failed after that the
        // retry shouldn't pay the 5-10 min cost again). If sync-bulk hasn't
        // run recently, fall through and run it now.
        $mostRecent = DB::table('scryfall_cards')->max('last_synced_at');
        $recentlySynced = $mostRecent && strtotime((string) $mostRecent) > time() - 3600;

        if (! $recentlySynced) {
            Log::info("drop_user_cards migration: {$before} FK orphan(s) detected, running scryfall:sync-bulk");
            fwrite(STDERR, ">>> {$before} FK orphan(s) detected; running scryfall:sync-bulk (this may take several minutes)...\n");
            try {
                Artisan::call('scryfall:sync-bulk');
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Aborted: scryfall:sync-bulk failed during migration pre-flight: '.$e->getMessage(),
                    previous: $e,
                );
            }
        } else {
            fwrite(STDERR, ">>> {$before} FK orphan(s) detected; scryfall:sync-bulk ran recently, skipping to per-card fetches...\n");
        }

        $remaining = $this->countOrphans();
        if ($remaining > 0) {
            fwrite(STDERR, ">>> {$remaining} orphan(s) still missing; fetching individually from Scryfall /cards/{id}...\n");
            $this->backfillOrphansFromScryfallApi();
        }

        $final = $this->countOrphans();
        if ($final > 0) {
            throw new \RuntimeException(sprintf(
                'Aborted: %d FK orphan(s) remain in collection_entries/deck_entries '
                .'after bulk sync AND per-card Scryfall fetches. The scryfall_ids are '
                ."either fabricated or have been permanently removed from Scryfall.\n"
                ."Inspect with:\n"
                ."  SELECT ce.scryfall_id, uc.name, uc.set_code FROM collection_entries ce "
                ."LEFT JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id "
                ."LEFT JOIN user_cards uc ON uc.scryfall_id = ce.scryfall_id "
                ."WHERE sc.scryfall_id IS NULL;\n"
                ."Remediation: delete the orphan entries, or manually insert stub "
                .'scryfall_cards rows if the card data should be preserved.',
                $final,
            ));
        }
    }

    /**
     * Fetch each remaining orphan scryfall_id individually from Scryfall's
     * /cards/{id} endpoint and upsert into scryfall_cards. This endpoint
     * returns non-English printings and variants that the default_cards
     * bulk file excludes. Uses the same BulkSyncService row-mapping logic
     * so the inserted rows are identical to bulk-synced ones.
     */
    private function backfillOrphansFromScryfallApi(): void
    {
        /** @var ScryfallService $scryfall */
        $scryfall = app(ScryfallService::class);
        /** @var BulkSyncService $bulk */
        $bulk = app(BulkSyncService::class);
        $now = now();

        $orphanIds = DB::table('collection_entries')
            ->select('collection_entries.scryfall_id')
            ->leftJoin('scryfall_cards', 'scryfall_cards.scryfall_id', '=', 'collection_entries.scryfall_id')
            ->whereNull('scryfall_cards.scryfall_id')
            ->union(
                DB::table('deck_entries')
                    ->select('deck_entries.scryfall_id')
                    ->leftJoin('scryfall_cards', 'scryfall_cards.scryfall_id', '=', 'deck_entries.scryfall_id')
                    ->whereNull('scryfall_cards.scryfall_id')
            )
            ->pluck('scryfall_id')
            ->unique()
            ->values();

        // applyBulkCardData is private on BulkSyncService, but Scryfall's
        // /cards/{id} response uses the same shape as bulk entries and
        // we want the same mapping (DFC handling, color canonicalisation,
        // partner_scope derivation). Reach it via reflection — this is
        // the only caller that lives outside the service itself.
        $ref = new \ReflectionMethod(BulkSyncService::class, 'applyBulkCardData');
        $ref->setAccessible(true);
        $flushRef = new \ReflectionMethod(BulkSyncService::class, 'flushScryfallCards');
        $flushRef->setAccessible(true);
        $flushRawRef = new \ReflectionMethod(BulkSyncService::class, 'flushScryfallCardsRaw');
        $flushRawRef->setAccessible(true);

        $cardRows = [];
        $rawRows = [];
        foreach ($orphanIds as $scryfallId) {
            try {
                $response = $scryfall->fetchCard($scryfallId);
            } catch (\Throwable $e) {
                Log::warning("drop_user_cards migration: /cards/{$scryfallId} fetch failed: {$e->getMessage()}");
                continue;
            }
            if ($response === null) {
                Log::warning("drop_user_cards migration: /cards/{$scryfallId} returned 404");
                continue;
            }

            $row = $ref->invoke($bulk, $response, $now);
            if ($row === null) {
                continue;
            }
            $cardRows[] = $row;

            if (isset($response['all_parts']) && is_array($response['all_parts']) && $response['all_parts'] !== []) {
                $rawRows[] = [
                    'scryfall_id' => $response['id'],
                    'all_parts'   => json_encode($response['all_parts']),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        if ($cardRows) {
            $flushRef->invoke($bulk, $cardRows);
        }
        if ($rawRows) {
            $flushRawRef->invoke($bulk, $rawRows);
        }
    }

    private function countOrphans(): int
    {
        $row = DB::selectOne("
            SELECT
              (SELECT COUNT(*) FROM collection_entries ce
                 LEFT JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id
                 WHERE sc.scryfall_id IS NULL) AS collection_orphans,
              (SELECT COUNT(*) FROM deck_entries de
                 LEFT JOIN scryfall_cards sc ON sc.scryfall_id = de.scryfall_id
                 WHERE sc.scryfall_id IS NULL) AS deck_orphans
        ");
        return (int) ($row->collection_orphans ?? 0) + (int) ($row->deck_orphans ?? 0);
    }
};
