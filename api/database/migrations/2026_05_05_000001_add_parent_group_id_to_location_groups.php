<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `parent_group_id` to `location_groups` so groups can nest arbitrarily.
 * NULL means "top level" (sibling to ungrouped locations). Cascade is
 * `nullOnDelete` — deleting a parent promotes children to top level rather
 * than wiping them, matching how `locations.group_id` already behaves.
 *
 * The composite index supports the recursive sidebar fetch which selects
 * `where user_id = ? and parent_group_id [IS NULL | = ?] order by sort_order`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_groups', function (Blueprint $table) {
            $table->foreignId('parent_group_id')
                ->nullable()
                ->after('user_id')
                ->constrained('location_groups')
                ->nullOnDelete();

            $table->index(['user_id', 'parent_group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('location_groups', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'parent_group_id', 'sort_order']);
            $table->dropConstrainedForeignId('parent_group_id');
        });
    }
};
