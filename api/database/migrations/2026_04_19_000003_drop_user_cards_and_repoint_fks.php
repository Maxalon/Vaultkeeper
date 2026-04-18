<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the `user_cards` table. Every column in user_cards duplicates
 * scryfall_cards except `last_scryfall_sync` (an obsolete per-user sync
 * cache), so the table was redundant the moment scryfall_cards landed.
 *
 * Repoints FK columns that used to reference user_cards (collection_entries
 * and deck_entries both keyed by scryfall_id) at scryfall_cards instead.
 *
 * Pre-flight integrity check aborts if any collection_entries or
 * deck_entries row references a scryfall_id missing from scryfall_cards.
 * Remediation: run `php artisan scryfall:sync-bulk` first.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: abort cleanly if the post-migration FKs would orphan.
        $counts = DB::selectOne("
            SELECT
              (SELECT COUNT(*) FROM collection_entries ce
                 LEFT JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id
                 WHERE sc.scryfall_id IS NULL) AS collection_orphans,
              (SELECT COUNT(*) FROM deck_entries de
                 LEFT JOIN scryfall_cards sc ON sc.scryfall_id = de.scryfall_id
                 WHERE sc.scryfall_id IS NULL) AS deck_orphans
        ");

        $collectionOrphans = (int) ($counts->collection_orphans ?? 0);
        $deckOrphans       = (int) ($counts->deck_orphans ?? 0);

        if ($collectionOrphans > 0 || $deckOrphans > 0) {
            throw new \RuntimeException(sprintf(
                'Aborted: %d collection_entries and %d deck_entries reference scryfall_ids not present in scryfall_cards. '
                .'Run `php artisan scryfall:sync-bulk` first, verify the orphan counts are zero, then retry.',
                $collectionOrphans,
                $deckOrphans,
            ));
        }

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
};
