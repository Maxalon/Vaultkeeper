<?php

namespace App\Services;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\MtgSet;
use App\Models\ScryfallCard;
use App\Models\SyncState;
use App\Models\UserCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Owns all bulk-data sync logic against Scryfall:
 *   - syncSets()         — full set catalogue
 *   - syncBulkCards()    — Default Cards JSON → scryfall_cards
 *   - syncOracleTags()   — per-tag Scryfall searches → card_oracle_tags
 *   - handleMigrations() — Scryfall card-id migrations (merge / delete)
 *   - syncSet()          — targeted resync of a single set
 *
 * Called by the scryfall:sync-bulk and scryfall:check-sets artisan commands.
 * Reads sync metadata (last_migration_check) from the sync_state table so
 * state survives `php artisan cache:clear`.
 */
class BulkSyncService
{
    /** Layouts whose card_faces[] hold front/back data. Mirrors CardSyncService. */
    private const DFC_LAYOUTS = [
        'transform',
        'modal_dfc',
        'double_faced_token',
        'reversible_card',
    ];

    /** Batch size for upserts into scryfall_cards / card_oracle_tags. */
    private const UPSERT_CHUNK = 500;

    /** Default sentinel for the very first migrations run. */
    private const MIGRATIONS_EPOCH = '1970-01-01T00:00:00Z';

    /** All Scryfall-recognized colour letters, sorted W U B R G. */
    private const ALL_COLORS = ['W', 'U', 'B', 'R', 'G'];

    public function __construct(
        private ScryfallService $scryfall,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Sets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pull every set from Scryfall and upsert into the `sets` table.
     * Refreshes our_card_count by joining against scryfall_cards afterwards.
     *
     * @return array{upserted: int}
     */
    public function syncSets(): array
    {
        $remote = $this->scryfall->fetchSets();
        $rows = [];
        $now = now();

        foreach ($remote as $s) {
            if (! isset($s['code'], $s['id'], $s['name'], $s['set_type'])) {
                continue;
            }
            $rows[] = [
                'scryfall_id'    => $s['id'],
                'code'           => strtolower($s['code']),
                'name'           => $s['name'],
                'set_type'       => $s['set_type'],
                'released_at'    => $s['released_at'] ?? null,
                'card_count'     => (int) ($s['card_count'] ?? 0),
                'icon_svg_uri'   => $s['icon_svg_uri'] ?? null,
                'search_uri'     => $s['search_uri'] ?? '',
                'last_synced_at' => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            MtgSet::upsert(
                $chunk,
                ['code'],
                ['scryfall_id', 'name', 'set_type', 'released_at',
                 'card_count', 'icon_svg_uri', 'search_uri',
                 'last_synced_at', 'updated_at'],
            );
        }

        $this->refreshOurCardCounts();

        $upserted = count($rows);
        Log::info("BulkSyncService::syncSets — upserted {$upserted} sets");

        return ['upserted' => $upserted];
    }

    /**
     * Recompute `sets.our_card_count` from a single JOIN against scryfall_cards.
     * Cheap; fine to run after every bulk operation.
     */
    private function refreshOurCardCounts(): void
    {
        DB::statement(<<<'SQL'
            UPDATE sets s
            LEFT JOIN (
                SELECT set_code, COUNT(*) AS c
                FROM scryfall_cards
                GROUP BY set_code
            ) sc ON sc.set_code = s.code
            SET s.our_card_count = COALESCE(sc.c, 0)
        SQL);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bulk cards
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Download Scryfall's Default Cards bulk file and upsert every card into
     * scryfall_cards. Caller is expected to have raised the memory limit
     * (the JSON file is ~700 MB; full json_decode peaks around 2-3 GB).
     *
     * @param  callable|null  $onProgress  fn(int $processed, int $total): void
     * @return array{processed: int, file: string}
     */
    public function syncBulkCards(?callable $onProgress = null): array
    {
        $manifest = $this->scryfall->fetchBulkDataManifest();
        $entry = collect($manifest)->firstWhere('type', 'default_cards');
        if (! $entry || empty($entry['download_uri'])) {
            throw new RuntimeException('Scryfall manifest missing default_cards entry');
        }
        $downloadUri = $entry['download_uri'];

        $dir = config('scryfall.bulk_dir');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $path = $dir . '/' . now()->format('Y-m-d') . '.json';

        // Use Storage-backed download for consistency with existing service.
        // We pass the file's path inside storage/app, so disk='local' resolves
        // to the same root as storage_path('app').
        $relPath = 'scryfall-bulk/' . basename($path);
        $ok = $this->scryfall->downloadToDisk($downloadUri, 'local', $relPath);
        if (! $ok) {
            throw new RuntimeException("Failed to download bulk file from {$downloadUri}");
        }

        // Decode the entire JSON in one shot. The user opted for raw throughput
        // over peak-memory frugality (16 GB RAM available); this avoids the
        // generator overhead of a streaming parser.
        $raw = File::get($path);
        $cards = json_decode($raw, true);
        unset($raw);
        if (! is_array($cards)) {
            File::delete($path);
            throw new RuntimeException('Failed to decode bulk JSON');
        }

        $now = now();
        $batch = [];
        $processed = 0;
        $total = count($cards);

        foreach ($cards as $card) {
            $row = $this->applyBulkCardData($card, $now);
            if ($row === null) {
                continue;
            }
            $batch[] = $row;

            if (count($batch) >= self::UPSERT_CHUNK) {
                $this->flushScryfallCards($batch);
                $processed += count($batch);
                $batch = [];
                if ($onProgress) $onProgress($processed, $total);
            }
        }

        if ($batch) {
            $this->flushScryfallCards($batch);
            $processed += count($batch);
            if ($onProgress) $onProgress($processed, $total);
        }

        // Free the in-memory array before the JOIN — pgsql/mysql client
        // memory + 2 GB array side-by-side is wasteful.
        unset($cards);

        $this->refreshOurCardCounts();

        // Done — clean up the bulk file.
        File::delete($path);

        Log::info("BulkSyncService::syncBulkCards — processed {$processed} cards");

        return ['processed' => $processed, 'file' => $path];
    }

    /**
     * Map a single Scryfall bulk card object onto a scryfall_cards row array,
     * ready for upsert. Mirrors the DFC-aware logic in CardSyncService::applyScryfallData()
     * but produces a flat row for batch INSERT instead of an UPDATE statement.
     *
     * Returns null when the input is missing required fields.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    private function applyBulkCardData(array $c, \Illuminate\Support\Carbon $now): ?array
    {
        if (! isset($c['id'], $c['oracle_id'], $c['name'], $c['set'])) {
            return null;
        }

        $layout = $c['layout'] ?? null;
        $faces  = $c['card_faces'] ?? null;
        $isDfc  = is_array($faces)
            && isset($faces[0], $faces[1])
            && in_array($layout, self::DFC_LAYOUTS, true);

        if ($isDfc) {
            $front = $faces[0];
            $back  = $faces[1];
            $frontImages = $front['image_uris'] ?? $c['image_uris'] ?? [];
            $backImages  = $back['image_uris'] ?? [];

            $faceFields = [
                'image_small'       => $frontImages['small'] ?? null,
                'image_normal'      => $frontImages['normal'] ?? null,
                'image_large'       => $frontImages['large'] ?? null,
                'image_small_back'  => $backImages['small'] ?? null,
                'image_normal_back' => $backImages['normal'] ?? null,
                'image_large_back'  => $backImages['large'] ?? null,
                'mana_cost'         => $front['mana_cost'] ?? null,
                'mana_cost_back'    => $back['mana_cost'] ?? null,
                'type_line'         => $front['type_line'] ?? null,
                'type_line_back'    => $back['type_line'] ?? null,
                'oracle_text'       => $front['oracle_text'] ?? null,
                'oracle_text_back'  => $back['oracle_text'] ?? null,
                'power'             => $front['power'] ?? null,
                'toughness'         => $front['toughness'] ?? null,
                'loyalty'           => $front['loyalty'] ?? null,
            ];
        } else {
            $images = $c['image_uris'] ?? [];
            $faceFields = [
                'image_small'       => $images['small'] ?? null,
                'image_normal'      => $images['normal'] ?? null,
                'image_large'       => $images['large'] ?? null,
                'image_small_back'  => null,
                'image_normal_back' => null,
                'image_large_back'  => null,
                'mana_cost'         => $c['mana_cost'] ?? null,
                'mana_cost_back'    => null,
                'type_line'         => $c['type_line'] ?? null,
                'type_line_back'    => null,
                'oracle_text'       => $c['oracle_text'] ?? null,
                'oracle_text_back'  => null,
                'power'             => $c['power'] ?? null,
                'toughness'         => $c['toughness'] ?? null,
                'loyalty'           => $c['loyalty'] ?? null,
            ];
        }

        // Top-level fields shared by all layouts.
        $colors         = $c['colors'] ?? [];
        $colorIdentity  = $c['color_identity'] ?? [];

        if ($isDfc && empty($colors)) {
            $faceColors = array_merge(
                $faces[0]['colors'] ?? [],
                $faces[1]['colors'] ?? [],
            );
            $colors = array_values(array_unique($faceColors));
        }

        return array_merge($faceFields, [
            'scryfall_id'      => $c['id'],
            'oracle_id'        => $c['oracle_id'],
            'name'             => $c['name'],
            'set_code'         => strtolower($c['set']),
            'collector_number' => (string) ($c['collector_number'] ?? ''),
            'rarity'           => $c['rarity'] ?? 'common',
            'layout'           => $layout ?? 'normal',
            'is_dfc'           => $isDfc,
            'cmc'              => isset($c['cmc']) ? (float) $c['cmc'] : null,
            'colors'           => json_encode(array_values($colors)),
            'color_identity'   => json_encode($this->canonicaliseColors($colorIdentity)),
            'legalities'       => isset($c['legalities']) ? json_encode($c['legalities']) : null,
            'keywords'         => isset($c['keywords']) ? json_encode($c['keywords']) : null,
            'edhrec_rank'      => isset($c['edhrec_rank']) ? (int) $c['edhrec_rank'] : null,
            'reserved'         => (bool) ($c['reserved'] ?? false),
            'last_synced_at'   => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Sort colors into canonical WUBRG order so the JSON column compares
     * exactly when the frontend's color_identity filter normalises the same way.
     *
     * @param  array<int, string>  $colors
     * @return array<int, string>
     */
    private function canonicaliseColors(array $colors): array
    {
        $upper = array_map('strtoupper', $colors);
        $order = array_flip(self::ALL_COLORS);
        usort($upper, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return array_values(array_unique($upper));
    }

    /** Batch upsert helper for scryfall_cards. */
    private function flushScryfallCards(array $rows): void
    {
        ScryfallCard::upsert(
            $rows,
            ['scryfall_id'],
            [
                'oracle_id', 'name', 'set_code', 'collector_number', 'rarity',
                'layout', 'is_dfc', 'mana_cost', 'cmc', 'colors',
                'color_identity', 'type_line', 'oracle_text', 'power',
                'toughness', 'loyalty', 'legalities', 'keywords',
                'image_small', 'image_normal', 'image_large',
                'image_small_back', 'image_normal_back', 'image_large_back',
                'mana_cost_back', 'type_line_back', 'oracle_text_back',
                'edhrec_rank', 'reserved', 'last_synced_at', 'updated_at',
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oracle tags
    // ─────────────────────────────────────────────────────────────────────

    /**
     * For each configured oracle tag, paginate through Scryfall search and
     * upsert (oracle_id, tag) pairs into card_oracle_tags. After all tags
     * are processed, delete tag rows whose oracle_id is no longer in
     * scryfall_cards (orphan cleanup).
     *
     * @return array<string, int>  per-tag count of unique oracle_ids written
     */
    public function syncOracleTags(): array
    {
        $tags = config('scryfall.oracle_tags', []);
        $now = now();
        $perTagCounts = [];

        foreach ($tags as $tag) {
            $oracleIds = [];
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                try {
                    $resp = $this->scryfall->searchCards("otag:{$tag}", $page, 'oracle');
                } catch (Throwable $e) {
                    Log::warning("syncOracleTags: search otag:{$tag} page {$page} failed: {$e->getMessage()}");
                    break;
                }

                foreach ((array) ($resp['data'] ?? []) as $card) {
                    if (isset($card['oracle_id'])) {
                        $oracleIds[$card['oracle_id']] = true;
                    }
                }

                $hasMore = (bool) ($resp['has_more'] ?? false);
                $page++; // increment regardless so we never loop on page 1
            }

            $rows = [];
            foreach (array_keys($oracleIds) as $oid) {
                $rows[] = [
                    'oracle_id'  => $oid,
                    'tag'        => $tag,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                CardOracleTag::upsert(
                    $chunk,
                    ['oracle_id', 'tag'],
                    ['updated_at'],
                );
            }

            $perTagCounts[$tag] = count($rows);
        }

        // Orphan cleanup — tag rows for cards we no longer have.
        DB::statement(<<<'SQL'
            DELETE t FROM card_oracle_tags t
            LEFT JOIN scryfall_cards c ON c.oracle_id = t.oracle_id
            WHERE c.oracle_id IS NULL
        SQL);

        Log::info('BulkSyncService::syncOracleTags — done', $perTagCounts);

        return $perTagCounts;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Migrations (Scryfall card-id renames / deletions)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Process every Scryfall migration since the last check. Each migration
     * runs in its own DB transaction so a single bad migration doesn't
     * poison the batch.
     *
     * @return array{processed: int, since: string}
     */
    public function handleMigrations(): array
    {
        $stateRow = SyncState::firstOrCreate(
            ['key' => 'last_migration_check'],
            ['value' => self::MIGRATIONS_EPOCH],
        );
        $since = $stateRow->value ?: self::MIGRATIONS_EPOCH;

        $migrations = $this->scryfall->fetchMigrations($since);
        $processed = 0;

        foreach ($migrations as $m) {
            $strategy = $m['migration_strategy'] ?? null;
            $oldId    = $m['old_scryfall_id'] ?? null;
            $newId    = $m['new_scryfall_id'] ?? null;

            if (! $oldId) {
                continue;
            }

            try {
                DB::transaction(function () use ($strategy, $oldId, $newId, $m) {
                    if ($strategy === 'merge' && $newId) {
                        $this->consolidateMerge($oldId, $newId);
                    } elseif ($strategy === 'delete') {
                        $this->markDeleted($oldId);
                    }
                });
                $processed++;
            } catch (Throwable $e) {
                Log::warning("Migration {$oldId} failed: {$e->getMessage()}");
            }
        }

        $stateRow->value = now()->toIso8601String();
        $stateRow->save();

        Log::info("BulkSyncService::handleMigrations — processed {$processed} migrations since {$since}");

        return ['processed' => $processed, 'since' => $since];
    }

    /**
     * Re-point user data from $oldId to $newId. Handles the user_cards UNIQUE
     * constraint by deleting the orphan when both rows exist locally, and
     * coalesces collection_entries that land on the same (location_id,
     * condition, foil) tuple by summing their quantity.
     */
    private function consolidateMerge(string $oldId, string $newId): void
    {
        // 1. user_cards UNIQUE handling
        $newExists = UserCard::where('scryfall_id', $newId)->exists();
        if ($newExists) {
            UserCard::where('scryfall_id', $oldId)->delete();
        } else {
            UserCard::where('scryfall_id', $oldId)->update(['scryfall_id' => $newId]);
        }

        // 2. collection_entries — coalesce on (location_id, condition, foil)
        $oldEntries = CollectionEntry::where('scryfall_id', $oldId)->get();
        foreach ($oldEntries as $old) {
            $sibling = CollectionEntry::where('scryfall_id', $newId)
                ->where('user_id', $old->user_id)
                ->where('location_id', $old->location_id)
                ->where('condition', $old->condition)
                ->where('foil', $old->foil)
                ->first();

            if ($sibling) {
                $sibling->quantity += $old->quantity;
                $sibling->save();
                $old->delete();
            } else {
                $old->scryfall_id = $newId;
                $old->save();
            }
        }

        // 3. deck_entries — distinct slots, no coalescing.
        DeckEntry::where('scryfall_id', $oldId)->update(['scryfall_id' => $newId]);

        // 4. card_oracle_tags — re-point oracle_id if both old/new rows present;
        //    UNIQUE (oracle_id, tag) means we drop dupes and keep one row each.
        //    Scryfall provides old/new oracle_id on merges where it changed.
        // Note: Scryfall's migration object uses `old_scryfall_id` / `new_scryfall_id`;
        // if oracle_id also changed, callers can extend handleMigrations() to pass it
        // through. For now, oracle_id is rarely changed by merges so we leave tags alone.
    }

    /**
     * Flag every collection / deck entry that pointed at $deletedId for the
     * user to review, and drop the matching scryfall_cards row. The user_cards
     * row is left intact so the user still sees their copy in their collection.
     */
    private function markDeleted(string $deletedId): void
    {
        CollectionEntry::where('scryfall_id', $deletedId)
            ->update(['needs_review' => true]);

        DeckEntry::where('scryfall_id', $deletedId)
            ->update(['needs_review' => true]);

        ScryfallCard::where('scryfall_id', $deletedId)->delete();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Targeted single-set sync
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resync one set's worth of cards via Scryfall's search API. Used by
     * scryfall:check-sets when a card_count mismatch is detected.
     *
     * @return array{set: string, cards: int}
     */
    public function syncSet(string $setCode): array
    {
        $setCode = strtolower($setCode);
        $page = 1;
        $hasMore = true;
        $now = now();
        $batch = [];
        $count = 0;

        while ($hasMore) {
            try {
                $resp = $this->scryfall->searchCards("e:{$setCode}", $page);
            } catch (Throwable $e) {
                Log::warning("syncSet({$setCode}) page {$page} failed: {$e->getMessage()}");
                break;
            }

            foreach ((array) ($resp['data'] ?? []) as $card) {
                $row = $this->applyBulkCardData($card, $now);
                if ($row !== null) {
                    $batch[] = $row;
                }
                if (count($batch) >= self::UPSERT_CHUNK) {
                    $this->flushScryfallCards($batch);
                    $count += count($batch);
                    $batch = [];
                }
            }

            $hasMore = (bool) ($resp['has_more'] ?? false);
            $page++;
        }

        if ($batch) {
            $this->flushScryfallCards($batch);
            $count += count($batch);
        }

        // Refresh just this set's count.
        $localCount = ScryfallCard::where('set_code', $setCode)->count();
        MtgSet::where('code', $setCode)->update([
            'our_card_count' => $localCount,
            'last_synced_at' => $now,
        ]);

        Log::info("BulkSyncService::syncSet({$setCode}) — synced {$count} cards");

        return ['set' => $setCode, 'cards' => $count];
    }
}
