<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->json('supertypes')->nullable()->after('type_line');
            $table->json('types')->nullable()->after('supertypes');
            $table->json('subtypes')->nullable()->after('types');
            $table->date('released_at')->nullable()->after('last_synced_at');
            $table->boolean('promo')->default(false)->after('released_at');
            $table->boolean('variation')->default(false)->after('promo');
            $table->string('set_type', 30)->nullable()->after('variation');
            $table->boolean('oversized')->default(false)->after('set_type');
            $table->boolean('is_default_eligible')->default(false)->after('oversized');
            $table->text('printed_text')->nullable()->after('oracle_text');
            $table->text('printed_text_back')->nullable()->after('oracle_text_back');

            $table->index('released_at');
            $table->index('is_default_eligible');
        });

        // MySQL 8+ multi-valued JSON indexes. Only added if not already present.
        // `keywords` index guarded because it may exist from prior work.
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE scryfall_cards '
                . 'ADD INDEX idx_supertypes ((CAST(supertypes AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_types      ((CAST(types      AS CHAR(40) ARRAY))), '
                . 'ADD INDEX idx_subtypes   ((CAST(subtypes   AS CHAR(40) ARRAY)))'
            );

            $hasKeywordsIdx = collect(DB::select(
                "SHOW INDEX FROM scryfall_cards WHERE Key_name = 'idx_keywords'"
            ))->isNotEmpty();
            if (! $hasKeywordsIdx) {
                DB::statement(
                    'ALTER TABLE scryfall_cards '
                    . 'ADD INDEX idx_keywords ((CAST(keywords AS CHAR(40) ARRAY)))'
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE scryfall_cards DROP INDEX idx_supertypes, DROP INDEX idx_types, DROP INDEX idx_subtypes');
            // Don't drop idx_keywords here: it may have predated this migration.
        }
        Schema::table('scryfall_cards', function (Blueprint $table) {
            $table->dropIndex(['released_at']);
            $table->dropIndex(['is_default_eligible']);
            $table->dropColumn([
                'supertypes', 'types', 'subtypes',
                'released_at', 'promo', 'variation', 'set_type',
                'oversized', 'is_default_eligible',
                'printed_text', 'printed_text_back',
            ]);
        });
    }
};
