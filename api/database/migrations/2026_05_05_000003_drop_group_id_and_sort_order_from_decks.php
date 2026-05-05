<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `decks.group_id` and `decks.sort_order`. The shadow Location row
 * (role='deck') is now the single source of truth for a deck's sidebar
 * position — see migration 2026_05_05_000002 for the backfill.
 *
 * Reversible by re-adding the columns nullable + repeating the backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropIndex(['user_id', 'sort_order']);
            $table->dropColumn(['group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()
                ->after('is_archived')
                ->constrained('location_groups')
                ->nullOnDelete();
            $table->integer('sort_order')->default(0)->after('group_id');

            $table->index(['user_id', 'sort_order']);
        });
    }
};
