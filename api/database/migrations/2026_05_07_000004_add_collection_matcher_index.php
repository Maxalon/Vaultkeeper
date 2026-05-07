<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the wanted-card matcher query (A4).
 *
 * The matcher executes a single SQL join:
 *
 *   SELECT ce.*
 *   FROM   collection_entries ce
 *   JOIN   locations l ON l.id = ce.location_id
 *   WHERE  ce.scryfall_id IN (<wanted card ids>)
 *     AND  ce.user_id    IN (<accepted friend ids>)
 *     AND  l.role = 'user'
 *
 * Without an index, MySQL must scan all collection_entries for each
 * (scryfall_id, friend) combination. With the composite index on
 * (scryfall_id, user_id, location_id), the engine can satisfy the
 * three-column equality predicate from the index alone (covering index)
 * and skip the heap fetch for rows that fail the location.role check.
 *
 * Column order rationale:
 *   1. scryfall_id first — the highest-cardinality equality filter; reduces
 *      the candidate set the most.
 *   2. user_id second — further narrows to only the friends' rows.
 *   3. location_id last — allows the index to be used as a covering index
 *      for the location_id equality lookup and avoids a separate FK join
 *      probe in the common case where location_id IS NOT NULL.
 *
 * Performance contract: EXPLAIN ANALYZE on 50 friends × 1000 cards seeded
 * data must stay under 50 ms (verified in A4 feature test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->index(
                ['scryfall_id', 'user_id', 'location_id'],
                'ce_matcher_scryfall_user_location',
            );
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropIndex('ce_matcher_scryfall_user_location');
        });
    }
};
