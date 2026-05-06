<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #33: drop oracle-invariant columns from scryfall_cards.
 *
 * #30 Phase 1 made scryfall_oracles the canonical search source and
 * BulkSyncService now writes those fields straight to that table. The
 * matching scryfall_cards columns are pure denormalisation — every read
 * in production has been re-pointed (via the $card->oracle relation +
 * accessors on ScryfallCard, plus an explicit join in DeckEntryController's
 * sort path), so it's safe to take them off the printings table.
 *
 * Reversibility: down() restores the schema but the rows come back NULL.
 * Repopulating requires a full `php artisan scryfall:sync-bulk` run, which
 * rewrites scryfall_oracles in the new shape — the down() path exists for
 * structural rollback, not data rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the four multi-valued JSON indexes added in
        //    2026_04_21_000001_add_catalog_columns_to_scryfall_cards.php.
        //    They indexed columns we're about to drop, so they have to
        //    go first or the column-drop will refuse.
        if (DB::getDriverName() === 'mysql') {
            foreach (['idx_supertypes', 'idx_types', 'idx_subtypes', 'idx_keywords'] as $idx) {
                $exists = collect(DB::select(
                    "SHOW INDEX FROM scryfall_cards WHERE Key_name = ?",
                    [$idx],
                ))->isNotEmpty();
                if ($exists) {
                    DB::statement("ALTER TABLE scryfall_cards DROP INDEX {$idx}");
                }
            }
        }

        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropColumn([
                'mana_cost', 'cmc',
                'colors', 'color_identity',
                'type_line', 'supertypes', 'types', 'subtypes',
                'oracle_text',
                'power', 'toughness', 'loyalty',
                'legalities',
                'keywords',
                'edhrec_rank',
                'reserved',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->string('mana_cost')->nullable()->after('is_dfc');
            $table->decimal('cmc', 12, 2)->nullable()->after('mana_cost');
            $table->json('colors')->nullable()->after('cmc');
            $table->json('color_identity')->nullable()->after('colors');
            $table->string('type_line')->nullable()->after('produced_mana');
            $table->json('supertypes')->nullable()->after('type_line');
            $table->json('types')->nullable()->after('supertypes');
            $table->json('subtypes')->nullable()->after('types');
            $table->text('oracle_text')->nullable()->after('subtypes');
            $table->string('power')->nullable()->after('oracle_text');
            $table->string('toughness')->nullable()->after('power');
            $table->string('loyalty')->nullable()->after('toughness');
            $table->json('legalities')->nullable()->after('loyalty');
            $table->json('keywords')->nullable()->after('legalities');
            $table->integer('edhrec_rank')->nullable()->after('keywords');
            $table->boolean('reserved')->default(false)->after('edhrec_rank');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE scryfall_cards '
                . 'ADD INDEX idx_supertypes ((CAST(supertypes AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_types      ((CAST(types      AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_subtypes   ((CAST(subtypes   AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_keywords   ((CAST(keywords   AS CHAR(40) ARRAY)))'
            );
        }
    }
};
