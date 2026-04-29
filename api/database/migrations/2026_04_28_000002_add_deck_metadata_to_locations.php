<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two columns to `locations`:
 *
 *   - `deck_id`  — points at the Deck this location physically backs (when
 *                  the location is the auto-created `Deck: <name>` storage
 *                  for that deck's cards). Cascades on deck delete so
 *                  unmarking-as-owned + deck deletion clean up together.
 *
 *   - `role`     — what THIS location is for from the data-model side:
 *                  `'user'`               regular drawer/binder
 *                  `'deck'`               auto-created deck-location
 *                  `'pending_relocation'` per-user "where did this card go?" bucket
 *
 * The presentation-layer field `kind` (emitted by LocationGroupController as
 * `'group' | 'location'`, augmented in the SPA with `'deck'` for deck rows)
 * is unrelated and stays untouched. Naming `role` here avoids collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('deck_id')
                ->nullable()
                ->after('user_id')
                ->constrained('decks')
                ->cascadeOnDelete();

            $table->enum('role', ['user', 'deck', 'pending_relocation'])
                ->default('user')
                ->after('deck_id');

            // One deck → at most one deck-location for a user.
            $table->unique(['user_id', 'deck_id']);
        });

        // Partial unique index: at most one pending_relocation location per user.
        // Native partial indexes vary by engine; emit explicit SQL when the
        // driver supports it. MySQL <8.0 silently rejects WHERE on UNIQUE; the
        // app enforces singleton via PendingRelocationService::ensureLocation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX locations_user_pending_unique '
                . "ON locations (user_id) WHERE role = 'pending_relocation'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS locations_user_pending_unique');
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'deck_id']);
            $table->dropConstrainedForeignId('deck_id');
            $table->dropColumn('role');
        });
    }
};
