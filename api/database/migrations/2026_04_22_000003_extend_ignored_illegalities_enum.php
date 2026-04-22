<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends `deck_ignored_illegalities.illegality_type` enum to include
 * `invalid_companion` — used by the companion-validity check in
 * DeckLegalityService. MySQL enum changes are schema-level; use raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deck_ignored_illegalities MODIFY COLUMN illegality_type ENUM(
            'banned_card',
            'color_identity_violation',
            'duplicate_card',
            'invalid_partner',
            'invalid_commander',
            'invalid_companion',
            'deck_size',
            'too_many_cards',
            'not_legal_in_format',
            'orphan_signature_spell',
            'missing_signature_spell'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deck_ignored_illegalities MODIFY COLUMN illegality_type ENUM(
            'banned_card',
            'color_identity_violation',
            'duplicate_card',
            'invalid_partner',
            'invalid_commander',
            'deck_size',
            'too_many_cards',
            'not_legal_in_format',
            'orphan_signature_spell',
            'missing_signature_spell'
        ) NOT NULL");
    }
};
