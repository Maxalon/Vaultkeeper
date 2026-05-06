<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic-locking version counter for collection_entries. Bumped on
 * every save by the HasOptimisticVersion trait; mutating endpoints
 * accept a `version` field in the request body and abort with 412 when
 * it doesn't match the current row.
 *
 * Default 0 so existing rows start with a known baseline; the trait
 * never reads zero as a "skip the check" sentinel — the absence of a
 * `version` field in the request payload is the opt-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
