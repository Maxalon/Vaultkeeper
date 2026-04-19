<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted "dismissed" illegalities per deck. DeckLegalityService always
 * recomputes from scratch; the controller diffs against rows in this table
 * to mark each result as ignored (or not) on the way out. Unique index
 * prevents the same illegality from being ignored twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_ignored_illegalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->enum('illegality_type', [
                'banned_card',
                'color_identity_violation',
                'duplicate_card',
                'invalid_partner',
                'invalid_commander',
                'deck_size',
                'too_many_cards',
                'not_legal_in_format',
                'orphan_signature_spell',
                'missing_signature_spell',
            ]);
            $table->uuid('scryfall_id_1')->nullable();
            $table->uuid('scryfall_id_2')->nullable();
            $table->uuid('oracle_id')->nullable();
            $table->unsignedInteger('expected_count')->nullable();
            $table->timestamps();

            $table->unique(
                ['deck_id', 'illegality_type', 'scryfall_id_1', 'scryfall_id_2', 'oracle_id'],
                'deck_ignored_illegalities_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_ignored_illegalities');
    }
};
