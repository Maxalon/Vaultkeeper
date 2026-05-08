<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized notification inbox (product decision 7).
 *
 * All event types — friend requests, bulk-import completions, deck-review
 * items — land in this single table. Frontend renders them from the
 * declarative `actions` JSON rather than hard-coding per-type logic.
 *
 * `type` examples:
 *   'friend.request_received'   — payload: {requester_id, requester_username}
 *   'friend.request_accepted'   — payload: {accepter_id, accepter_username}
 *   'collection.bulk_import_completed'
 *   'deck.card_marked_for_review'
 *
 * `actions` shape (array of objects):
 *   {
 *     "key":            "accept",
 *     "label":          "Accept",
 *     "kind":           "default" | "danger",
 *     "endpoint":       "/friends/requests/7",
 *     "method":         "PATCH",
 *     "body":           {"action": "accept"},
 *     "invalidates_on": [
 *       {"model": "Friendship", "id": 7, "field": "status", "version": 0}
 *     ]
 *   }
 *
 * Staleness is detected in A5 by comparing the `version` stored inside each
 * `invalidates_on` entry against the model's current `version` column
 * (HasOptimisticVersion). The notifications endpoint computes `available`
 * per-action at read time.
 *
 * In-app only — no email, no push (product decision 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // recipient

            // Dot-namespaced type string, e.g. 'friend.request_received'.
            $table->string('type');

            // Type-specific data (e.g. {requester_id, requester_username}).
            $table->json('payload');

            // Declarative action buttons the SPA renders.
            // Nullable — some notifications are informational only.
            $table->json('actions')->nullable();

            // NULL = unread; set to now() when user marks as read.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The two access patterns:
            //   1. Unread count / filter: WHERE user_id = ? AND read_at IS NULL
            //   2. Feed (newest first):   WHERE user_id = ? ORDER BY created_at DESC
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
