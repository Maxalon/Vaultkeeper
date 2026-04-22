<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog search acceleration — issue #30 Phase 1.
 *
 * Flat denormalised oracle table: one row per oracle_id (~37k rows) vs. the
 * ~113k printings scryfall_cards holds. Search reads from here instead of
 * window-wrapping scryfall_cards, which removes the 7-key ROW_NUMBER() sort,
 * the DISTINCT oracle_id count pass, and the sets LEFT JOIN.
 *
 * Populated at the end of scryfall:sync-bulk via BulkSyncService::syncOracleTable().
 * Derived table — dropping it and reverting the controller is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scryfall_oracles', function (Blueprint $table) {
            // oracle_id is canonical; non-incrementing char(36) PK.
            $table->uuid('oracle_id')->primary();

            // Default representative printing (pre-resolved; replaces the
            // per-query ROW_NUMBER() OVER (PARTITION BY oracle_id …)).
            $table->uuid('default_scryfall_id');
            $table->string('default_set_code');
            $table->string('default_collector_number');
            $table->date('default_released_at')->nullable();
            $table->string('default_rarity');
            $table->string('default_image_small')->nullable();
            $table->string('default_image_normal')->nullable();
            $table->string('default_image_large')->nullable();

            // Oracle-level fields (oracle-invariant across printings).
            $table->string('name');
            $table->string('layout');
            $table->boolean('is_dfc')->default(false);
            $table->string('mana_cost')->nullable();
            $table->decimal('cmc', 12, 2)->nullable();
            $table->json('colors')->nullable();
            $table->json('color_identity')->nullable();
            $table->string('type_line')->nullable();
            $table->json('supertypes')->nullable();
            $table->json('types')->nullable();
            $table->json('subtypes')->nullable();
            $table->text('oracle_text')->nullable();
            $table->text('printed_text')->nullable();
            $table->string('power')->nullable();
            $table->string('toughness')->nullable();
            $table->string('loyalty')->nullable();
            $table->json('legalities')->nullable();
            $table->json('keywords')->nullable();
            $table->integer('edhrec_rank')->nullable();
            $table->boolean('reserved')->default(false);
            $table->boolean('commander_game_changer')->default(false);
            $table->string('partner_scope')->nullable();

            // DFC back-face fields copied from the default printing.
            $table->string('mana_cost_back')->nullable();
            $table->string('type_line_back')->nullable();
            $table->text('oracle_text_back')->nullable();
            $table->text('printed_text_back')->nullable();
            $table->string('image_small_back')->nullable();
            $table->string('image_normal_back')->nullable();
            $table->string('image_large_back')->nullable();

            // Aggregates across all printings of this oracle.
            $table->integer('printing_count')->default(0);
            $table->date('max_released_at')->nullable();

            // Default-hide pre-computes. excluded_from_catalog replaces the
            // sets LEFT JOIN + INELIGIBLE_SET_TYPES IN-list: true when every
            // printing is in a hard-excluded set_type (art_series / funny /
            // token / etc.) AND none are playtest (playtest carves through).
            $table->boolean('is_playtest_any')->default(false);
            $table->boolean('excluded_from_catalog')->default(false);

            // Oracle-level layout flags (is:dfc / is:transform / etc. queries).
            // Mirror the current scryfall_cards handlers — oracle-invariant
            // because layout is per-oracle, not per-printing.
            $table->boolean('is_transform')->default(false);
            $table->boolean('is_mdfc')->default(false);
            $table->boolean('is_flip')->default(false);
            $table->boolean('is_meld')->default(false);
            $table->boolean('is_split')->default(false);
            $table->boolean('is_leveler')->default(false);

            // WUBRG bit-masks — W=1, U=2, B=4, R=8, G=16. Replaces the
            // JSON_CONTAINS/JSON_OVERLAPS/JSON_LENGTH dance for c: / ci: /
            // commander: with a single indexable integer op.
            $table->unsignedTinyInteger('color_identity_bits')->default(0);
            $table->unsignedTinyInteger('colors_bits')->default(0);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('cmc');
            $table->index('edhrec_rank');
            $table->index('default_released_at');
            $table->index('color_identity_bits');
            $table->index('colors_bits');
            $table->index('excluded_from_catalog');
        });

        // MySQL 8+ multi-valued JSON indexes — same pattern as
        // 2026_04_21_000001_add_catalog_columns_to_scryfall_cards.php.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE scryfall_oracles '
                . 'ADD INDEX idx_oracles_supertypes ((CAST(supertypes AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_oracles_types      ((CAST(types      AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_oracles_subtypes   ((CAST(subtypes   AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_oracles_keywords   ((CAST(keywords   AS CHAR(40) ARRAY)))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scryfall_oracles');
    }
};
