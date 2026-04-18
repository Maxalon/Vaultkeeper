<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert `decks.format` from a nullable varchar to a strict enum limited to
 * the five supported formats. Any existing row whose format falls outside
 * the allowlist is deleted — per the DB-1 plan (solo-dev environment, no
 * decks of record yet). Re-create decks with matching formats afterward.
 */
return new class extends Migration
{
    private const FORMATS = ['commander', 'oathbreaker', 'pauper', 'standard', 'modern'];

    public function up(): void
    {
        DB::table('decks')->whereNotIn('format', self::FORMATS)->delete();

        $allowed = "'".implode("','", self::FORMATS)."'";
        DB::statement("ALTER TABLE decks MODIFY format ENUM({$allowed}) NOT NULL");
    }

    public function down(): void
    {
        // Revert to nullable varchar; any existing rows keep their values.
        DB::statement('ALTER TABLE decks MODIFY format VARCHAR(255) NULL');
    }
};
