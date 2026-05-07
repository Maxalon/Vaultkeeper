<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `version` column to `friendships` so the model can use
 * HasOptimisticVersion for notification staleness detection (A5).
 *
 * The notification gateway (POST /notifications/{id}/actions/{key})
 * snapshots the friendship's version at notification-creation time
 * (inside the `invalidates_on` action payload). On re-execution the
 * gateway re-fetches the friendship and compares the current version
 * against the snapshot — mismatch → 409 Stale.
 *
 * The column starts at 0 for all existing rows; HasOptimisticVersion
 * will bump it to 1 on the first subsequent save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friendships', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->default(0)->after('responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('friendships', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
