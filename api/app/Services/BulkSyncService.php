<?php

namespace App\Services;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\MtgSet;
use App\Models\MtgType;
use App\Models\ScryfallCard;
use App\Models\ScryfallCardRaw;
use App\Models\SyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    /** Layouts whose card_faces[] hold front/back data. */
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

    /**
     * Supertype whitelist for type-line parsing. Kept as a constant rather
     * than a DB lookup because the list is stable (Wizards rarely introduces
     * new supertypes) and parsing runs per card during bulk sync.
     */
    private const SUPERTYPES = [
        'Basic', 'Legendary', 'Snow', 'World', 'Elite', 'Ongoing', 'Host', 'Token',
    ];

    /** Scryfall /catalog/* endpoints we pull into mtg_type_catalog. */
    private const TYPE_CATALOG_ENDPOINTS = [
        'supertype'            => 'supertypes',
        'card_type'            => 'card-types',
        'creature_subtype'     => 'creature-types',
        'planeswalker_subtype' => 'planeswalker-types',
        'land_subtype'         => 'land-types',
        'artifact_subtype'     => 'artifact-types',
        'enchantment_subtype'  => 'enchantment-types',
        'spell_subtype'        => 'spell-types',
    ];

    /**
     * Set types that are hard-excluded from the catalog entirely.
     * art_series is Wizards' gallery-style reprint sets (alternate-art
     * Commanders with no gameplay function). Un-sets live under `funny`.
     *
     * Formerly included `vanguard`, `planechase`, `archenemy` — those
     * are now soft-hidden at the card-type level (DEFAULT_HIDDEN_TYPES
     * on CardSearchService) so queries like `t:scheme` / `t:plane` /
     * `t:vanguard` can surface them. Hard-excluding by set_type blocked
     * those type-based searches entirely.
     *
     * Public so the catalog controller applies the same exclusion list.
     */
    public const INELIGIBLE_SET_TYPES = [
        'memorabilia', 'funny', 'token', 'minigame',
        'art_series',
    ];

    /**
     * Set codes exempted from the hard set_type exclusion above. Mystery
     * Booster Playtest sets (cmb1, cmb2) are filed under set_type='funny'
     * in our current sets sync — same bucket as Unhinged/Unstable — but
     * their contents are legitimate cards a user might want to search
     * via `is:playtest`. Scryfall's taxonomy has since introduced a
     * dedicated `playtest` set_type; this carve-out can be reduced to
     * an empty list once a fresh sets sync propagates that value.
     */
    public const PLAYTEST_SET_CODES = ['cmb1', 'cmb2'];

    /**
     * Set types that disqualify a printing from being the DEFAULT
     * representative but are still catalogued. The hard-exclude list
     * (INELIGIBLE_SET_TYPES) is intentionally duplicated here too, so
     * every excluded card is also tagged ineligible-as-default — belt
     * and suspenders, since those rows never reach the catalog anyway.
     *
     * The "premium product" set types catch printings that DON'T get
     * caught by the per-card frame/border/foil/promo checks, most notably
     * Secret Lair (`box`) — SLD cards can be perfectly normal-looking
     * (nonfoil, black border, no frame_effects) but still shouldn't be
     * the default representative when a real expansion printing exists.
     * If an oracle only has SLD / masterpiece / eternal printings, every
     * printing ties on eligibility and the released-date sort picks
     * the newest — so SLD-only cards still surface correctly.
     */
    private const NOT_DEFAULT_SET_TYPES = [
        // Hard-excluded types (duplicated for defense in depth).
        'memorabilia', 'funny', 'token', 'minigame',
        // Premium / special-printing products — in-catalog but never default.
        'box',            // Secret Lair Drop, Secret Lair: Ultimate, Secret Lair Countdown
        'masterpiece',    // Expeditions / Invocations / Inventions / Through the Ages
        'from_the_vault', 'premium_deck', 'spellbook',
        'eternal',        // all-foil premium supplements (Avatar: TLA Eternal, …)
        'promo',          // caught by the per-card promo flag too, but be explicit
    ];

    /**
     * In-memory cache of multi-word subtypes loaded once per bulk sync from
     * mtg_type_catalog. Populated by loadMultiWordSubtypes() — parseTypeLine
     * consults this before whitespace-splitting the subtype half of a type line.
     *
     * @var array<int, string>
     */
    private array $multiWordSubtypes = [];

    public function __construct(
        private ScryfallService $scryfall,
        private PriceUpsertService $prices,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Type catalog
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pull every Scryfall /catalog/* list into mtg_type_catalog. Run at the
     * start of bulk sync so parseTypeLine() can recognise multi-word subtypes
     * like "Time Lord" before splitting on whitespace.
     *
     * @return array<string, int>  per-category count of upserted rows
     */
    public function syncTypeCatalog(): array
    {
        $now = now();
        $counts = [];

        foreach (self::TYPE_CATALOG_ENDPOINTS as $category => $endpoint) {
            try {
                $names = $this->scryfall->fetchCatalog($endpoint);
            } catch (Throwable $e) {
                Log::warning("syncTypeCatalog {$endpoint} failed: {$e->getMessage()}");
                $counts[$category] = 0;
                continue;
            }

            $rows = [];
            foreach ($names as $name) {
                $rows[] = [
                    'category'      => $category,
                    'name'          => $name,
                    'is_multi_word' => str_contains($name, ' '),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                MtgType::upsert(
                    $chunk,
                    ['category', 'name'],
                    ['is_multi_word', 'updated_at'],
                );
            }

            $counts[$category] = count($rows);
        }

        Log::info('BulkSyncService::syncTypeCatalog — done', $counts);

        return $counts;
    }

    /**
     * Populate $this->multiWordSubtypes from mtg_type_catalog. Call after
     * syncTypeCatalog() and before the first applyBulkCardData() invocation.
     */
    public function loadMultiWordSubtypes(): void
    {
        $this->multiWordSubtypes = MtgType::query()
            ->whereIn('category', MtgType::SUBTYPE_CATEGORIES)
            ->where('is_multi_word', true)
            ->orderByRaw('LENGTH(name) DESC') // longest match wins when scanning
            ->pluck('name')
            ->all();
    }

    /**
     * Parse a Scryfall `type_line` into {supertypes, types, subtypes}. Splits
     * on em-dash (U+2014), en-dash (U+2013), or hyphen. Greedy-matches known
     * multi-word subtypes (from mtg_type_catalog, passed in via $multiWord)
     * before splitting the subtype half on whitespace.
     *
     * Public so tests can drive the pure function directly.
     *
     * @param  array<int, string>  $multiWord  multi-word subtypes, longest first
     * @return array{supertypes: array<int, string>, types: array<int, string>, subtypes: array<int, string>}
     */
    public function parseTypeLine(string $typeLine, array $multiWord): array
    {
        $typeLine = trim($typeLine);
        if ($typeLine === '') {
            return ['supertypes' => [], 'types' => [], 'subtypes' => []];
        }

        // Handle em-dash (U+2014), en-dash (U+2013), or plain hyphen.
        $parts = preg_split('/\s+[\x{2014}\x{2013}\-]\s+/u', $typeLine, 2);
        $left  = $parts[0] ?? '';
        $right = $parts[1] ?? '';

        $supertypes = [];
        $types = [];
        foreach (preg_split('/\s+/', trim($left)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            if (in_array($tok, self::SUPERTYPES, true)) {
                $supertypes[] = $tok;
            } else {
                $types[] = $tok;
            }
        }

        $subtypes = [];
        $remaining = trim($right);
        foreach ($multiWord as $mw) {
            // Match word-bounded so "Time" doesn't clobber "Time Lord".
            // preg_quote handles edge characters safely.
            $pattern = '/(?<!\S)' . preg_quote($mw, '/') . '(?!\S)/u';
            if (preg_match($pattern, $remaining)) {
                $subtypes[] = $mw;
                $remaining = trim(preg_replace($pattern, ' ', $remaining, 1));
            }
        }
        foreach (preg_split('/\s+/', $remaining) ?: [] as $tok) {
            if ($tok !== '') {
                $subtypes[] = $tok;
            }
        }

        return [
            'supertypes' => $supertypes,
            'types'      => $types,
            'subtypes'   => $subtypes,
        ];
    }

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
                // Scryfall flags Arena-only / MTGO-only sets (Alchemy,
                // Pioneer Masters, Historic Anthology, etc.) with this
                // boolean. Used downstream to hide their printings from
                // the picker and the catalog.
                'digital'        => (bool) ($s['digital'] ?? false),
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
                ['scryfall_id', 'name', 'set_type', 'digital', 'released_at',
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
        // Type catalog feeds parseTypeLine's multi-word subtype matcher.
        // Run first so the very first card in the bulk feed parses correctly.
        $this->syncTypeCatalog();
        $this->loadMultiWordSubtypes();

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
        $rawBatch = [];
        $priceBatch = [];
        // Oracle-invariant fields write to scryfall_oracles directly during
        // this pass — first card seen per oracle_id wins. Rep fields and
        // aggregates on the oracle row are placeholders here; syncOracleTable
        // overwrites them after handleMigrations + pruneStaleCards.
        $oracleData = [];
        $processed = 0;
        $total = count($cards);

        foreach ($cards as $card) {
            $payload = $this->applyBulkCardData($card, $now);
            if ($payload === null) {
                continue;
            }
            $batch[] = $payload['card'];

            $oid = $payload['oracle']['oracle_id'];
            if (! isset($oracleData[$oid])) {
                $oracleData[$oid] = $payload['oracle'];
            }

            // Raw companion row — only emit when all_parts is present;
            // a NULL all_parts is indistinguishable from "no raw row
            // needed" on the read side.
            if (isset($card['id'], $card['all_parts']) && is_array($card['all_parts']) && $card['all_parts'] !== []) {
                $rawBatch[] = [
                    'scryfall_id' => $card['id'],
                    'all_parts'   => json_encode($card['all_parts']),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            // Price companion row — null when the printing carries no EUR
            // price at all. Flushed parallel to the cards batch so prices
            // stay fresh on day-1 of new releases without waiting for the
            // next daily price job.
            $priceRow = $this->prices->buildRow($card, $now);
            if ($priceRow !== null) {
                $priceBatch[] = $priceRow;
            }

            if (count($batch) >= self::UPSERT_CHUNK) {
                $this->flushScryfallCards($batch);
                if ($rawBatch) {
                    $this->flushScryfallCardsRaw($rawBatch);
                    $rawBatch = [];
                }
                if ($priceBatch) {
                    $this->prices->upsertRows($priceBatch);
                    $priceBatch = [];
                }
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
        if ($rawBatch) {
            $this->flushScryfallCardsRaw($rawBatch);
        }
        if ($priceBatch) {
            $this->prices->upsertRows($priceBatch);
        }

        // Flush the deduped per-oracle batch to scryfall_oracles. Oracle-
        // invariant fields land here authoritatively; rep + aggregates
        // are still placeholders until syncOracleTable runs.
        foreach (array_chunk(array_values($oracleData), self::UPSERT_CHUNK) as $chunk) {
            $this->flushScryfallOracles($chunk);
        }

        // Free the in-memory array before the JOIN — pgsql/mysql client
        // memory + 2 GB array side-by-side is wasteful.
        unset($cards, $oracleData);

        $this->refreshOurCardCounts();

        // Done — clean up the bulk file.
        File::delete($path);

        Log::info("BulkSyncService::syncBulkCards — processed {$processed} cards");

        return ['processed' => $processed, 'file' => $path];
    }

    /**
     * Map a single Scryfall bulk card object onto two row arrays — one for
     * scryfall_cards (per-printing) and one for scryfall_oracles (oracle-
     * invariant). Returns null when the input is missing required fields.
     *
     * The oracle row carries provisional values for rep / aggregate columns
     * (default_*, image_*_back, name, layout, printing_count, max_released_at,
     * is_playtest_any, excluded_from_catalog) — those satisfy NOT NULL on
     * insert and get overwritten by syncOracleTable's UPDATE pass after
     * handleMigrations + pruneStaleCards have settled the printing set.
     *
     * @param  array<string, mixed>  $c
     * @return array{card: array<string, mixed>, oracle: array<string, mixed>}|null
     */
    public function applyBulkCardData(array $c, \Illuminate\Support\Carbon $now): ?array
    {
        if (! isset($c['id'], $c['name'], $c['set'])) {
            return null;
        }

        // Paper-only filter. Arena-only / MTGO-only printings never show up
        // in our catalog, so drop them at intake rather than carrying them
        // through the catalog search.
        if (! in_array('paper', (array) ($c['games'] ?? []), true)) {
            return null;
        }

        $layout = $c['layout'] ?? null;
        $faces  = $c['card_faces'] ?? null;
        $isDfc  = is_array($faces)
            && isset($faces[0], $faces[1])
            && in_array($layout, self::DFC_LAYOUTS, true);

        // Reversible cards don't carry a top-level oracle_id — each face has
        // its own. Use the front face's oracle_id as the card's canonical
        // oracle identity so downstream oracle_id joins (tags, duplicates,
        // etc.) still work.
        $oracleId = $c['oracle_id']
            ?? ($isDfc ? ($faces[0]['oracle_id'] ?? null) : null);
        if ($oracleId === null) {
            return null;
        }

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
                'printed_text'      => $front['printed_text'] ?? null,
                'printed_text_back' => $back['printed_text'] ?? null,
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
                'printed_text'      => $c['printed_text'] ?? null,
                'printed_text_back' => null,
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

        // Derive split types from the front-face (or single) type line. DFC
        // backs can have their own subtypes but we don't index them yet;
        // full-body search via `fo:`/`o:` still picks those up textually.
        $parsedTypes = $this->parseTypeLine(
            $faceFields['type_line'] ?? '',
            $this->multiWordSubtypes,
        );

        $promo      = (bool) ($c['promo']      ?? false);
        $variation  = (bool) ($c['variation']  ?? false);
        $oversized  = (bool) ($c['oversized']  ?? false);
        $setType    = $c['set_type'] ?? null;
        $layout     = $layout ?? 'normal';
        $setCode    = strtolower($c['set']);
        $collector  = (string) ($c['collector_number'] ?? '');
        $rarity     = $c['rarity'] ?? 'common';
        $isPlaytest =
            in_array('playtest', (array) ($c['promo_types'] ?? []), true)
            // Fallback for Mystery Booster Playtest sets, which mark
            // cards via set membership rather than promo_types.
            || in_array($c['set'] ?? '', self::PLAYTEST_SET_CODES, true);
        $partnerScope = $this->derivePartnerScope(
            (array) ($c['keywords'] ?? []),
            $faceFields['oracle_text'] ?? ($c['oracle_text'] ?? null),
        );
        $gameChanger = (bool) ($c['game_changer'] ?? false);
        $canonColorIdentity = $this->canonicaliseColors($colorIdentity);

        // Per-printing scryfall_cards row — oracle-invariant fields
        // (cmc / colors / type_line / oracle_text / legalities / keywords /
        // edhrec_rank / reserved / supertypes / types / subtypes) all live
        // on scryfall_oracles now; see issue #33.
        $card = [
            'scryfall_id'         => $c['id'],
            'oracle_id'           => $oracleId,
            'name'                => $c['name'],
            'set_code'            => $setCode,
            'collector_number'    => $collector,
            'rarity'              => $rarity,
            'layout'              => $layout,
            'is_dfc'              => $isDfc,
            'image_small'         => $faceFields['image_small'],
            'image_normal'        => $faceFields['image_normal'],
            'image_large'         => $faceFields['image_large'],
            'image_small_back'    => $faceFields['image_small_back'],
            'image_normal_back'   => $faceFields['image_normal_back'],
            'image_large_back'    => $faceFields['image_large_back'],
            'mana_cost_back'      => $faceFields['mana_cost_back'],
            'type_line_back'      => $faceFields['type_line_back'],
            'oracle_text_back'    => $faceFields['oracle_text_back'],
            'printed_text'        => $faceFields['printed_text'],
            'printed_text_back'   => $faceFields['printed_text_back'],
            'produced_mana'       => isset($c['produced_mana']) ? json_encode(array_values($c['produced_mana'])) : null,
            'finishes'            => isset($c['finishes']) ? json_encode(array_values($c['finishes'])) : null,
            'released_at'         => $c['released_at'] ?? null,
            'promo'               => $promo,
            'variation'           => $variation,
            'set_type'            => $setType,
            'oversized'           => $oversized,
            'is_default_eligible' => $this->deriveDefaultEligible($c),
            'is_playtest'         => $isPlaytest,
            'commander_game_changer' => $gameChanger,
            'partner_scope'       => $partnerScope,
            'last_synced_at'      => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        // Oracle row — oracle-invariant fields are authoritative; rep /
        // aggregate fields (default_*, image_*_back, name, layout flags,
        // printing_count, max_released_at, is_playtest_any,
        // excluded_from_catalog) carry provisional values that
        // syncOracleTable's UPDATE pass overwrites.
        $oracle = [
            'oracle_id'                => $oracleId,
            // Provisional rep fields — first card seen wins; syncOracleTable
            // resolves to the actual rep printing.
            'default_scryfall_id'      => $c['id'],
            'default_set_code'         => $setCode,
            'default_collector_number' => $collector,
            'default_released_at'      => $c['released_at'] ?? null,
            'default_rarity'           => $rarity,
            'default_image_small'      => $faceFields['image_small'],
            'default_image_normal'     => $faceFields['image_normal'],
            'default_image_large'      => $faceFields['image_large'],
            // Oracle-invariant fields (authoritative).
            'name'                     => $c['name'],
            'layout'                   => $layout,
            'is_dfc'                   => $isDfc,
            'mana_cost'                => $faceFields['mana_cost'],
            'cmc'                      => isset($c['cmc']) ? (float) $c['cmc'] : null,
            'colors'                   => json_encode(array_values($colors)),
            'color_identity'           => json_encode($canonColorIdentity),
            'type_line'                => $faceFields['type_line'],
            'supertypes'               => json_encode($parsedTypes['supertypes']),
            'types'                    => json_encode($parsedTypes['types']),
            'subtypes'                 => json_encode($parsedTypes['subtypes']),
            'oracle_text'              => $faceFields['oracle_text'],
            'printed_text'             => $faceFields['printed_text'],
            'power'                    => $faceFields['power'],
            'toughness'                => $faceFields['toughness'],
            'loyalty'                  => $faceFields['loyalty'],
            'legalities'               => isset($c['legalities']) ? json_encode($c['legalities']) : null,
            'keywords'                 => isset($c['keywords']) ? json_encode($c['keywords']) : null,
            'edhrec_rank'              => isset($c['edhrec_rank']) ? (int) $c['edhrec_rank'] : null,
            'reserved'                 => (bool) ($c['reserved'] ?? false),
            'commander_game_changer'   => $gameChanger,
            'partner_scope'            => $partnerScope,
            // Back-face oracle-invariant fields.
            'mana_cost_back'           => $faceFields['mana_cost_back'],
            'type_line_back'           => $faceFields['type_line_back'],
            'oracle_text_back'         => $faceFields['oracle_text_back'],
            'printed_text_back'        => $faceFields['printed_text_back'],
            // Provisional rep-image-back; resolved by syncOracleTable.
            'image_small_back'         => $faceFields['image_small_back'],
            'image_normal_back'        => $faceFields['image_normal_back'],
            'image_large_back'         => $faceFields['image_large_back'],
            // Layout flags — derived from layout (oracle-invariant).
            'is_transform'             => $layout === 'transform',
            'is_mdfc'                  => $layout === 'modal_dfc',
            'is_flip'                  => $layout === 'flip',
            'is_meld'                  => $layout === 'meld',
            'is_split'                 => $layout === 'split',
            'is_leveler'               => $layout === 'leveler',
            // Bit-masks — derived from canonicalised colors.
            'color_identity_bits'      => self::buildColorBits($canonColorIdentity),
            'colors_bits'              => self::buildColorBits(array_values($colors)),
            // Provisional aggregates — overwritten by syncOracleTable.
            'printing_count'           => 1,
            'max_released_at'          => $c['released_at'] ?? null,
            'is_playtest_any'          => $isPlaytest,
            'excluded_from_catalog'    => false,
            'last_synced_at'           => $now,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        return ['card' => $card, 'oracle' => $oracle];
    }

    /**
     * Map a Scryfall card's Partner variant to a flat scope string.
     *
     * Plain Partner (including "Partner with X", which also grants plain
     * Partner) returns 'plain'. Variants like "Partner—Friends forever",
     * "Partner—Survivors", "Partner—Character select" return a snake_case
     * label derived from the text between the em-dash and the parenthesized
     * reminder text. Unknown future "Partner—Foo" variants are picked up
     * automatically by the same regex.
     *
     * Returns null for cards that don't have the Partner keyword at all.
     * Public so tests can drive the pure function directly.
     *
     * @param  array<int, string>  $keywords
     */
    /**
     * Frame effects that mark a printing as an alt treatment. Presence
     * of ANY of these on a card disqualifies it from being a default
     * representative. Everything else is treated as intrinsic — the
     * ordinary frame for that card type (enchantment-frame, legendary
     * gold border, snow, spree, lesson, tombstone, devoid, miracle,
     * companion stamp, DFC layout markers, …) — which are normal
     * printings, not alt-art variants.
     */
    public const ALT_FRAME_EFFECTS = [
        'showcase',
        'extendedart',
        'etched',
        'nyxtouched',
        'colorshifted',
        'inverted',
        'shatteredglass',
    ];

    /**
     * Border colors acceptable for a default printing. Everything else —
     * borderless (alt-art), silver (Un-sets), gold (World Championship
     * decks), yellow (Alchemy rebalanced / digital-only frame) — is
     * either not tournament-legal on paper or is a collector variant.
     */
    public const ALLOWED_BORDER_COLORS = ['black', 'white'];

    /**
     * True when a printing is a sensible "default representative" for its
     * oracle — a normal, non-promo, non-alt-treatment printing that a user
     * would expect to see when browsing the catalog. Run per card during
     * bulk sync; public for the unit test DataProvider.
     *
     * Rules (ALL must hold):
     *   - nonfoil is true              — rules out foil-only alt treatments
     *   - frame_effects contains none of ALT_FRAME_EFFECTS — the list is
     *                                    a denylist, not an allowlist,
     *                                    because many frame effects are
     *                                    intrinsic to a card's nature
     *                                    (enchantment, legendary, snow,
     *                                    spree, tombstone, devoid, …) —
     *                                    they'd falsely trigger an
     *                                    allowlist approach.
     *   - border_color in ALLOWED_BORDER_COLORS — only black/white qualify
     *   - promo is false               — catches promo-stamped printings
     *                                    regardless of set_type
     *   - variation is false           — Scryfall's flag for alternate
     *                                    versions of the same collector number
     *   - oversized is false           — old Plane / oversized commander cards
     *   - set_type is not in the "not default" list — even if all the
     *     frame flags look normal, these sets never contain sensible
     *     defaults (memorabilia / funny / token / minigame, plus
     *     premium-product lines; see NOT_DEFAULT_SET_TYPES).
     *
     * Callers that want the hard-exclusion list (art_series, vanguard,
     * planechase, archenemy as well) use INELIGIBLE_SET_TYPES at the
     * catalog-query layer — those rows never appear in the catalog and
     * don't need per-card marking.
     *
     * @param  array<string, mixed>  $c  raw Scryfall bulk card object
     */
    public function deriveDefaultEligible(array $c): bool
    {
        // Defense-in-depth: the per-card `games` array filter at intake
        // already drops Arena-only / MTGO-only printings, but a digital
        // card sneaking past (e.g. legacy data, or a future Scryfall
        // schema shift) should never be a default representative.
        if ((bool) ($c['digital'] ?? false)) return false;

        if (! (bool) ($c['nonfoil'] ?? false)) return false;

        $frameEffects = (array) ($c['frame_effects'] ?? []);
        if (count(array_intersect($frameEffects, self::ALT_FRAME_EFFECTS)) > 0) return false;

        if (! in_array($c['border_color'] ?? null, self::ALLOWED_BORDER_COLORS, true)) {
            return false;
        }

        if ((bool) ($c['promo']     ?? false)) return false;
        if ((bool) ($c['variation'] ?? false)) return false;
        if ((bool) ($c['oversized'] ?? false)) return false;

        if (in_array($c['set_type'] ?? null, self::NOT_DEFAULT_SET_TYPES, true)) {
            return false;
        }

        return true;
    }

    public function derivePartnerScope(array $keywords, ?string $oracleText): ?string
    {
        if (! in_array('Partner', $keywords, true)) {
            return null;
        }
        // em-dash (U+2014) or literal hyphen. The front-loaded character
        // class ensures plain "Partner (..." and "Partner with X (..."
        // do NOT match — they fall through to 'plain'.
        if (preg_match('/Partner[\x{2014}-]([A-Za-z ]+?)\s*\(/u', $oracleText ?? '', $m)) {
            return Str::snake(trim($m[1]));
        }
        return 'plain';
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
    public function flushScryfallCards(array $rows): void
    {
        ScryfallCard::upsert(
            $rows,
            ['scryfall_id'],
            [
                'oracle_id', 'name', 'set_code', 'collector_number', 'rarity',
                'layout', 'is_dfc', 'produced_mana', 'finishes',
                'image_small', 'image_normal', 'image_large',
                'image_small_back', 'image_normal_back', 'image_large_back',
                'mana_cost_back', 'type_line_back', 'oracle_text_back',
                'printed_text', 'printed_text_back',
                'released_at', 'promo', 'variation', 'set_type',
                'oversized', 'is_default_eligible', 'is_playtest',
                'commander_game_changer', 'partner_scope',
                'last_synced_at', 'updated_at',
            ],
        );
    }

    /**
     * Batch upsert helper for scryfall_oracles. Updates only the fields
     * we know are oracle-invariant — leaves rep/aggregate columns alone
     * on collision so a partial chunk of a re-run doesn't clobber the
     * resolved rep before syncOracleTable runs.
     */
    public function flushScryfallOracles(array $rows): void
    {
        \App\Models\ScryfallOracle::upsert(
            $rows,
            ['oracle_id'],
            [
                'name', 'layout', 'is_dfc',
                'mana_cost', 'cmc', 'colors', 'color_identity',
                'type_line', 'supertypes', 'types', 'subtypes',
                'oracle_text', 'printed_text', 'power', 'toughness', 'loyalty',
                'legalities', 'keywords', 'edhrec_rank', 'reserved',
                'commander_game_changer', 'partner_scope',
                'mana_cost_back', 'type_line_back', 'oracle_text_back', 'printed_text_back',
                'is_transform', 'is_mdfc', 'is_flip', 'is_meld', 'is_split', 'is_leveler',
                'color_identity_bits', 'colors_bits',
                'last_synced_at', 'updated_at',
            ],
        );
    }

    /** Batch upsert helper for scryfall_cards_raw. */
    private function flushScryfallCardsRaw(array $rows): void
    {
        ScryfallCardRaw::upsert(
            $rows,
            ['scryfall_id'],
            ['all_parts', 'updated_at'],
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
     * @param  callable|null  $onProgress  fn(int $tagsDone, int $totalTags, string $currentTag): void
     *                                     fires once when a tag starts (count not yet incremented)
     *                                     and again when that tag completes
     * @return array<string, int>  per-tag count of unique oracle_ids written
     */
    public function syncOracleTags(?callable $onProgress = null): array
    {
        $tags = config('scryfall.oracle_tags', []);
        $totalTags = count($tags);
        $now = now();
        $perTagCounts = [];
        $done = 0;

        foreach ($tags as $tag) {
            if ($onProgress) $onProgress($done, $totalTags, $tag);
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
            $done++;
            if ($onProgress) $onProgress($done, $totalTags, $tag);
        }

        // Orphan cleanup — tag rows for cards we no longer have.
        DB::statement(<<<'SQL'
            DELETE t FROM card_oracle_tags t
            LEFT JOIN scryfall_cards c ON c.oracle_id = t.oracle_id
            WHERE c.oracle_id IS NULL
        SQL);

        // Dropped-tag cleanup — rows whose tag is no longer in the configured
        // list (e.g. an operator removed `wheel` from config/scryfall.php).
        // Without this, dropped tags would silently linger in card_oracle_tags
        // forever and keep showing up on otag: searches and card detail.
        if (! empty($tags)) {
            DB::table('card_oracle_tags')->whereNotIn('tag', $tags)->delete();
        }

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
     * Re-point user data from $oldId to $newId. If only $oldId exists in
     * scryfall_cards, rename it (FK cascades carry the children). If both
     * exist, migrate children first — collection_entries coalesce on
     * (location_id, condition, foil), deck_entries don't — then drop the
     * now-orphaned old scryfall_cards row.
     */
    private function consolidateMerge(string $oldId, string $newId): void
    {
        $newExists = ScryfallCard::where('scryfall_id', $newId)->exists();

        if (! $newExists) {
            // Simple rename path — children follow via ON UPDATE CASCADE on
            // the scryfall_id FKs.
            ScryfallCard::where('scryfall_id', $oldId)->update(['scryfall_id' => $newId]);
            return;
        }

        // Both rows exist in scryfall_cards — migrate children to $newId
        // first (FKs are RESTRICT on delete), then drop the old card row.

        // 1. collection_entries — coalesce on (location_id, condition, foil, is_etched).
        //    Etched is a separate finish flavour from foil and prices
        //    independently on Cardmarket; keeping it in the merge key
        //    prevents an etched copy from collapsing into a foil copy
        //    (or vice versa) during a Scryfall card-id rename.
        $oldEntries = CollectionEntry::where('scryfall_id', $oldId)->get();
        foreach ($oldEntries as $old) {
            $sibling = CollectionEntry::where('scryfall_id', $newId)
                ->where('user_id', $old->user_id)
                ->where('location_id', $old->location_id)
                ->where('condition', $old->condition)
                ->where('foil', $old->foil)
                ->where('is_etched', $old->is_etched)
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

        // 2. deck_entries — distinct slots, no coalescing.
        DeckEntry::where('scryfall_id', $oldId)->update(['scryfall_id' => $newId]);

        // 3. Drop the now-unreferenced old scryfall_cards row. scryfall_cards_raw
        //    cascades via its own FK.
        ScryfallCard::where('scryfall_id', $oldId)->delete();

        // 4. card_oracle_tags — UNIQUE (oracle_id, tag) means duplicate tag
        //    rows would collide. Scryfall's migration object uses
        //    old_scryfall_id / new_scryfall_id; if oracle_id also changed,
        //    callers can extend handleMigrations() to pass it through. For
        //    now, oracle_id rarely changes on merges so we leave tags alone.
    }

    /**
     * Flag every collection_entry that pointed at $deletedId for the user
     * to review. The scryfall_cards row itself is kept so the user still
     * sees their card data (image, text) alongside the review reason —
     * dropping it would cascade into FK issues and remove the info the
     * user needs to decide what to do with the stale reference.
     *
     * deck_entries don't carry a review_reason; the review surface
     * focuses on physical copies (CEs). A stale scryfall_id in a
     * deck_entry is harmless until the user clicks through to bind a
     * copy, at which point the picker exposes the issue.
     */
    private function markDeleted(string $deletedId): void
    {
        CollectionEntry::where('scryfall_id', $deletedId)
            ->update(['review_reason' => \App\Enums\ReviewReason::CardDataChanged]);
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
        $oracleData = [];
        $count = 0;

        // applyBulkCardData parses type_line via the multi-word subtype
        // matcher; bulk sync warms it once at the top of syncBulkCards
        // but the targeted single-set path runs in isolation.
        if ($this->multiWordSubtypes === []) {
            $this->loadMultiWordSubtypes();
        }

        while ($hasMore) {
            try {
                $resp = $this->scryfall->searchCards("e:{$setCode}", $page);
            } catch (Throwable $e) {
                Log::warning("syncSet({$setCode}) page {$page} failed: {$e->getMessage()}");
                break;
            }

            foreach ((array) ($resp['data'] ?? []) as $card) {
                $payload = $this->applyBulkCardData($card, $now);
                if ($payload !== null) {
                    $batch[] = $payload['card'];
                    $oid = $payload['oracle']['oracle_id'];
                    if (! isset($oracleData[$oid])) {
                        $oracleData[$oid] = $payload['oracle'];
                    }
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

        foreach (array_chunk(array_values($oracleData), self::UPSERT_CHUNK) as $chunk) {
            $this->flushScryfallOracles($chunk);
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

    // ─────────────────────────────────────────────────────────────────────
    // Oracle table (catalog search acceleration — issue #30)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * WUBRG bit-mask from a sequence of colour letters. W=1, U=2, B=4,
     * R=8, G=16; unknown letters are ignored; duplicates are deduped so
     * `['W','W']` and `['W']` both yield 1. Public so tests can exercise
     * it directly and the controller can bind deck-identity predicates.
     *
     * @param  array<int, string>|null  $letters
     */
    public static function buildColorBits(?array $letters): int
    {
        if ($letters === null) {
            return 0;
        }
        $bit = ['W' => 1, 'U' => 2, 'B' => 4, 'R' => 8, 'G' => 16];
        $mask = 0;
        foreach ($letters as $l) {
            $m = $bit[strtoupper((string) $l)] ?? 0;
            $mask |= $m;
        }
        return $mask;
    }

    /**
     * Default staleness window for pruneStaleCards(). 21 days gives Scryfall
     * three weekly bulk-sync windows to surface a card before we drop it —
     * enough headroom to absorb a transient outage on their side without
     * triggering false-positive deletions.
     */
    public const STALE_CARD_THRESHOLD_DAYS = 21;

    /**
     * Delete scryfall_cards rows whose last_synced_at is older than
     * $thresholdDays. Catches two cases in one sweep:
     *
     *   1. Digital-only Alchemy / MTGO-only printings sitting in the table
     *      from before the paper-only intake filter — they never reappear
     *      in the bulk feed, so their last_synced_at never advances.
     *   2. Cards Scryfall has dropped from the catalog entirely (errata-
     *      removed, merged into another oracle, etc.). Anything we still
     *      cared about would have been rewritten by handleMigrations()
     *      first; whatever remains stale really is gone.
     *
     * Rows referenced by user data (collection_entries / deck_entries) are
     * skipped — those FKs are RESTRICT, so the DELETE would error anyway,
     * but more importantly we never want to silently corrupt a user's
     * collection/deck. They surface in the returned `protected` count so
     * an operator can investigate (and resolve via scryfall:purge-non-paper,
     * which has the online preflight to back-check Scryfall).
     *
     * scryfall_cards_raw rows cascade-delete on the FK so they clean up
     * automatically. decks.commander_*_scryfall_id and companion_scryfall_id
     * are SET NULL on delete — safe.
     *
     * @return array{deleted: int, protected: int, cutoff: string}
     */
    public function pruneStaleCards(int $thresholdDays = self::STALE_CARD_THRESHOLD_DAYS): array
    {
        $cutoff = now()->subDays($thresholdDays);

        // Pinned: stale rows still referenced by user data. Count first
        // (before the DELETE shrinks the candidate pool) so we can surface
        // them to the operator — the existing scryfall:purge-non-paper
        // command handles their cleanup with an online preflight.
        $protected = DB::table('scryfall_cards')
            ->where('last_synced_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereExists(function ($sq) {
                    $sq->from('collection_entries')
                        ->whereColumn('collection_entries.scryfall_id', 'scryfall_cards.scryfall_id');
                })->orWhereExists(function ($sq) {
                    $sq->from('deck_entries')
                        ->whereColumn('deck_entries.scryfall_id', 'scryfall_cards.scryfall_id');
                });
            })
            ->count();

        // Delete the unreferenced stale rows in one shot. Using NOT EXISTS
        // (correlated subquery) keeps the work in the DB — no PHP-side
        // ID materialization, no chunked IN() lists.
        $deleted = DB::table('scryfall_cards')
            ->where('last_synced_at', '<', $cutoff)
            ->whereNotExists(function ($q) {
                $q->from('collection_entries')
                    ->whereColumn('collection_entries.scryfall_id', 'scryfall_cards.scryfall_id');
            })
            ->whereNotExists(function ($q) {
                $q->from('deck_entries')
                    ->whereColumn('deck_entries.scryfall_id', 'scryfall_cards.scryfall_id');
            })
            ->delete();

        if ($deleted > 0 || $protected > 0) {
            Log::info(
                "BulkSyncService::pruneStaleCards — deleted {$deleted} stale rows, "
                . "protected {$protected} via user-data FKs (cutoff: {$cutoff->toDateTimeString()})"
            );
        }

        return [
            'deleted'   => $deleted,
            'protected' => $protected,
            'cutoff'    => $cutoff->toDateTimeString(),
        ];
    }

    /**
     * Resolve scryfall_oracles' rep + aggregate columns. Run after
     * handleMigrations + pruneStaleCards so it sees the post-merge,
     * post-prune printing set.
     *
     * Oracle-invariant fields (cmc, type_line, oracle_text, legalities,
     * keywords, …) were already written authoritatively to
     * scryfall_oracles by syncBulkCards; this method only:
     *   1. deletes orphan oracles (no surviving printing);
     *   2. UPDATEs rep fields (default_*, image_*_back, name, layout +
     *      derived layout flags) by joining a window-ranked subquery
     *      over scryfall_cards;
     *   3. UPDATEs aggregates (printing_count, max_released_at,
     *      is_playtest_any, excluded_from_catalog);
     *   4. recomputes color_identity_bits / colors_bits from the
     *      now-canonical color JSON columns (in case a test or repair
     *      flow wrote color_identity without bits).
     *
     * Idempotent. Issue #30 set up scryfall_oracles; issue #33 dropped
     * the oracle-invariant columns from scryfall_cards and split the
     * write path so rep + aggregate stay computable here without
     * needing those columns on the source table.
     *
     * @return array{oracles: int}
     */
    public function syncOracleTable(): array
    {
        $excludedList = "'" . implode("','", self::INELIGIBLE_SET_TYPES) . "'";

        // 1. Orphan cleanup — oracles whose every printing was pruned
        //    by pruneStaleCards (or removed via handleMigrations) no
        //    longer have a scryfall_cards row.
        DB::statement(<<<'SQL'
            DELETE so FROM scryfall_oracles so
            LEFT JOIN scryfall_cards sc ON sc.oracle_id = so.oracle_id
            WHERE sc.scryfall_id IS NULL
        SQL);

        // 2. Insert skeleton rows for any oracle that exists in
        //    scryfall_cards but not yet in scryfall_oracles (e.g., when
        //    syncOracleTable runs outside the syncBulkCards path —
        //    targeted set syncs, tests). The skeleton carries the
        //    oracle_id only; the UPDATE below fills in the rep and
        //    aggregate columns. Oracle-invariant columns stay at their
        //    table defaults (NULL JSON, empty strings) — production
        //    callers always go through syncBulkCards first, so this is
        //    purely a safety net for partial flows.
        DB::statement(<<<'SQL'
            INSERT INTO scryfall_oracles (
                oracle_id,
                default_scryfall_id, default_set_code, default_collector_number, default_rarity,
                name, layout, is_dfc,
                printing_count,
                created_at, updated_at
            )
            SELECT DISTINCT
                sc.oracle_id,
                sc.scryfall_id, sc.set_code, sc.collector_number, sc.rarity,
                sc.name, sc.layout, sc.is_dfc,
                0,
                NOW(), NOW()
            FROM scryfall_cards sc
            LEFT JOIN scryfall_oracles so ON so.oracle_id = sc.oracle_id
            WHERE so.oracle_id IS NULL
            GROUP BY sc.oracle_id
        SQL);

        // 3. Rep UPDATE — pick the best representative printing per
        //    oracle and copy its identifiers / images / layout onto
        //    scryfall_oracles. Same priority order the controller used
        //    pre-#30 (minus the user-specific "owned wins" tier).
        DB::statement(<<<'SQL'
            UPDATE scryfall_oracles so
            JOIN (
                SELECT * FROM (
                    SELECT
                        sc.oracle_id, sc.scryfall_id, sc.set_code, sc.collector_number,
                        sc.released_at, sc.rarity, sc.name, sc.layout, sc.is_dfc,
                        sc.image_small, sc.image_normal, sc.image_large,
                        sc.image_small_back, sc.image_normal_back, sc.image_large_back,
                        ROW_NUMBER() OVER (
                            PARTITION BY sc.oracle_id
                            ORDER BY
                                CASE WHEN sc.is_default_eligible THEN 0 ELSE 1 END,
                                sc.promo ASC,
                                COALESCE(sc.released_at, ms.released_at) DESC,
                                sc.set_code ASC,
                                -- collector_number can be '14p', '★123',
                                -- 'prerelease'… extract digits via
                                -- REGEXP_SUBSTR so strict-mode UPDATE
                                -- doesn't trip on the cast.
                                CAST(REGEXP_SUBSTR(sc.collector_number, '[0-9]+') AS UNSIGNED) ASC,
                                sc.collector_number ASC
                        ) AS rn
                    FROM scryfall_cards sc
                    LEFT JOIN sets ms ON ms.code = sc.set_code
                ) ranked
                WHERE rn = 1
            ) rep ON rep.oracle_id = so.oracle_id
            SET so.default_scryfall_id      = rep.scryfall_id,
                so.default_set_code         = rep.set_code,
                so.default_collector_number = rep.collector_number,
                so.default_released_at      = rep.released_at,
                so.default_rarity           = rep.rarity,
                so.default_image_small      = rep.image_small,
                so.default_image_normal     = rep.image_normal,
                so.default_image_large      = rep.image_large,
                so.image_small_back         = rep.image_small_back,
                so.image_normal_back        = rep.image_normal_back,
                so.image_large_back         = rep.image_large_back,
                so.name                     = rep.name,
                so.layout                   = rep.layout,
                so.is_dfc                   = rep.is_dfc,
                so.is_transform             = (rep.layout = 'transform'),
                so.is_mdfc                  = (rep.layout = 'modal_dfc'),
                so.is_flip                  = (rep.layout = 'flip'),
                so.is_meld                  = (rep.layout = 'meld'),
                so.is_split                 = (rep.layout = 'split'),
                so.is_leveler               = (rep.layout = 'leveler'),
                so.updated_at               = NOW()
        SQL);

        // 4. Aggregate UPDATE — printing_count, max_released_at,
        //    is_playtest_any, excluded_from_catalog. Set-type / digital
        //    rollup is the only place we still need the sets JOIN.
        DB::statement(<<<SQL
            UPDATE scryfall_oracles so
            JOIN (
                SELECT
                    sc.oracle_id,
                    COUNT(*) AS printing_count,
                    MAX(COALESCE(sc.released_at, ms.released_at)) AS max_released_at,
                    MAX(CASE WHEN sc.is_playtest THEN 1 ELSE 0 END) AS is_playtest_any,
                    -- A printing is "kept" (counts toward catalog visibility) if
                    -- its set_type is unknown, OR it's not in the hard-excluded
                    -- list AND not on a digital-only set, OR it's a playtest
                    -- card (carve-out). If zero printings are kept, the entire
                    -- oracle is hidden from search.
                    CASE WHEN SUM(
                        CASE WHEN (ms.set_type IS NULL
                                   OR (ms.set_type NOT IN ({$excludedList})
                                       AND COALESCE(ms.digital, 0) = 0)
                                   OR sc.is_playtest = 1)
                             THEN 1 ELSE 0 END
                    ) = 0 THEN 1 ELSE 0 END AS excluded_from_catalog
                FROM scryfall_cards sc
                LEFT JOIN sets ms ON ms.code = sc.set_code
                GROUP BY sc.oracle_id
            ) aggs ON aggs.oracle_id = so.oracle_id
            SET so.printing_count        = aggs.printing_count,
                so.max_released_at       = aggs.max_released_at,
                so.is_playtest_any       = aggs.is_playtest_any,
                so.excluded_from_catalog = aggs.excluded_from_catalog
SQL);

        // 5. Bit-mask refresh — recompute from the canonical color JSON
        //    columns. Cheap and idempotent; protects against stale bits
        //    if a colors / color_identity write went through any path
        //    other than syncBulkCards.
        DB::statement(<<<'SQL'
            UPDATE scryfall_oracles
            SET color_identity_bits =
                    (COALESCE(JSON_CONTAINS(color_identity, '"W"'), 0) * 1
                   + COALESCE(JSON_CONTAINS(color_identity, '"U"'), 0) * 2
                   + COALESCE(JSON_CONTAINS(color_identity, '"B"'), 0) * 4
                   + COALESCE(JSON_CONTAINS(color_identity, '"R"'), 0) * 8
                   + COALESCE(JSON_CONTAINS(color_identity, '"G"'), 0) * 16),
                colors_bits =
                    (COALESCE(JSON_CONTAINS(colors, '"W"'), 0) * 1
                   + COALESCE(JSON_CONTAINS(colors, '"U"'), 0) * 2
                   + COALESCE(JSON_CONTAINS(colors, '"B"'), 0) * 4
                   + COALESCE(JSON_CONTAINS(colors, '"R"'), 0) * 8
                   + COALESCE(JSON_CONTAINS(colors, '"G"'), 0) * 16)
        SQL);

        $count = (int) DB::table('scryfall_oracles')->count();
        Log::info("BulkSyncService::syncOracleTable — resolved {$count} oracles");

        return ['oracles' => $count];
    }
}
