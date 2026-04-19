<?php

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
     * FKs will reference. Runs `scryfall:sync-bulk` if and only if there's
     * at least one orphan — skips the ~5-minute sync on already-in-sync envs.
     */
    private function ensureScryfallCardsCoverage(): void
    {
        if ($this->countOrphans() === 0) {
            return;
        }

        $before = $this->countOrphans();
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

        $after = $this->countOrphans();
        if ($after > 0) {
            throw new \RuntimeException(sprintf(
                'Aborted: %d FK orphan(s) remain in collection_entries/deck_entries '
                .'after running scryfall:sync-bulk. The referenced scryfall_ids do not '
                .'exist in the Scryfall bulk data — likely deleted printings. Inspect '
                .'the orphan rows with:\n'
                ."  SELECT * FROM collection_entries ce LEFT JOIN scryfall_cards sc "
                ."ON sc.scryfall_id = ce.scryfall_id WHERE sc.scryfall_id IS NULL;\n"
                ."  SELECT * FROM deck_entries de LEFT JOIN scryfall_cards sc "
                ."ON sc.scryfall_id = de.scryfall_id WHERE sc.scryfall_id IS NULL;\n"
                .'Then either delete the orphans or manually insert stub scryfall_cards rows.',
                $after,
            ));
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
