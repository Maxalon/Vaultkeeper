<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friend graph storage. A single row represents the relationship between
 * two users regardless of direction.
 *
 * Canonical (least, greatest) ordering on (user_a_id, user_b_id) enforces
 * the "one row per pair" invariant at the DB level. Writers must always set
 * user_a_id = min(requester, addressee) and user_b_id = max(...) before
 * inserting — the unique index then makes double-insert impossible.
 *
 * The `requester_id` column carries the directional bit (who sent the
 * request) without breaking the canonical ordering.
 *
 * Status lifecycle:
 *   pending  → accepted  (addressee calls PATCH with action=accept)
 *   pending  → declined  (addressee calls PATCH with action=decline)
 *   declined is terminal — no transitions out. Re-requesting after decline
 *   is handled by the application layer returning 409.
 *   There is no 'blocked' value (product decision 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();

            // Canonical pair — always (least uid, greatest uid).
            // Never use foreignId() here: we need unsigned bigint but the FK
            // name must be explicit to avoid conflicts on $user_a vs $user_b.
            $table->unsignedBigInteger('user_a_id');
            $table->unsignedBigInteger('user_b_id');
            $table->unsignedBigInteger('requester_id'); // who initiated

            $table->foreign('user_a_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user_b_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('requester_id')->references('id')->on('users')->cascadeOnDelete();

            // No 'blocked' — product decision 5. No 'public' — not applicable.
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');

            $table->timestamp('responded_at')->nullable(); // set when accepted or declined
            $table->timestamps();

            // One row per pair — enforced by the unique constraint.
            $table->unique(['user_a_id', 'user_b_id']);

            // Speed up "show me all pending/accepted rows where I am user_a or user_b"
            $table->index(['user_a_id', 'status']);
            $table->index(['user_b_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
