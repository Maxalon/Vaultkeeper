<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user privacy configuration.
 *
 * One row per user; rows are created on demand (firstOrCreate) when the
 * user first accesses the privacy settings endpoint, or eagerly by the
 * User::created observer introduced in A2.
 *
 * Visibility enums are intentionally limited to ('friends', 'private') —
 * there is no 'public' value. This enforces product decision 2 ("No public
 * mode. Ever.") at the schema level so it can never be introduced by accident.
 *
 * `discoverable` controls whether the user appears in the username-prefix
 * search (/users/search). Setting it to false is the opt-out mechanism;
 * it does NOT break existing friendships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_privacy_settings', function (Blueprint $table) {
            // user_id is also the primary key — one row per user.
            $table->unsignedBigInteger('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // 'public' is deliberately absent — product decision 2.
            $table->enum('collection_visibility', ['friends', 'private'])->default('friends');
            $table->enum('decks_visibility', ['friends', 'private'])->default('friends');

            // If false, this user is excluded from /users/search results.
            $table->boolean('discoverable')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_privacy_settings');
    }
};
