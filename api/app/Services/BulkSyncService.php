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
        $processed = 0;
        $total = count($cards);

        foreach ($cards as $card) {
            $row = $this->applyBulkCardData($card, $now);
            if ($row === null) {
                continue;
            }
            $batch[] = $row;

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

            if (count($batch) >= self::UPSERT_CHUNK) {
                $this->flushScryfallCards($batch);
                if ($rawBatch) {
                    $this->flushScryfallCardsRaw($rawBatch);
                    $rawBatch = [];
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
     * ready for upsert. Returns null when the input is missing required fields.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
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

        return array_merge($faceFields, [
            'scryfall_id'      => $c['id'],
            'oracle_id'        => $oracleId,
            'name'             => $c['name'],
            'set_code'         => strtolower($c['set']),
            'collector_number' => (string) ($c['collector_number'] ?? ''),
            'rarity'           => $c['rarity'] ?? 'common',
            'layout'           => $layout ?? 'normal',
            'is_dfc'           => $isDfc,
            'cmc'              => isset($c['cmc']) ? (float) $c['cmc'] : null,
            'colors'           => json_encode(array_values($colors)),
            'color_identity'   => json_encode($this->canonicaliseColors($colorIdentity)),
            'produced_mana'    => isset($c['produced_mana']) ? json_encode(array_values($c['produced_mana'])) : null,
            'legalities'       => isset($c['legalities']) ? json_encode($c['legalities']) : null,
            'keywords'         => isset($c['keywords']) ? json_encode($c['keywords']) : null,
            'supertypes'       => json_encode($parsedTypes['supertypes']),
            'types'            => json_encode($parsedTypes['types']),
            'subtypes'         => json_encode($parsedTypes['subtypes']),
            'released_at'      => $c['released_at'] ?? null,
            'promo'            => $promo,
            'variation'        => $variation,
            'set_type'         => $setType,
            'oversized'        => $oversized,
            'is_default_eligible' => $this->deriveDefaultEligible($c),
            'is_playtest'         =>
                in_array('playtest', (array) ($c['promo_types'] ?? []), true)
                // Fallback for Mystery Booster Playtest sets, which mark
                // cards via set membership rather than promo_types.
                || in_array($c['set'] ?? '', self::PLAYTEST_SET_CODES, true),
            'edhrec_rank'      => isset($c['edhrec_rank']) ? (int) $c['edhrec_rank'] : null,
            'reserved'         => (bool) ($c['reserved'] ?? false),
            'commander_game_changer' => (bool) ($c['game_changer'] ?? false),
            'partner_scope'    => $this->derivePartnerScope(
                (array) ($c['keywords'] ?? []),
                $faceFields['oracle_text'] ?? ($c['oracle_text'] ?? null),
            ),
            'last_synced_at'   => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
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
                'layout', 'is_dfc', 'mana_cost', 'cmc', 'colors',
                'color_identity', 'produced_mana', 'type_line', 'oracle_text', 'power',
                'toughness', 'loyalty', 'legalities', 'keywords',
                'image_small', 'image_normal', 'image_large',
                'image_small_back', 'image_normal_back', 'image_large_back',
                'mana_cost_back', 'type_line_back', 'oracle_text_back',
                'printed_text', 'printed_text_back',
                'supertypes', 'types', 'subtypes',
                'released_at', 'promo', 'variation', 'set_type',
                'oversized', 'is_default_eligible', 'is_playtest',
                'edhrec_rank', 'reserved',
                'commander_game_changer', 'partner_scope',
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

        // 1. collection_entries — coalesce on (location_id, condition, foil)
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
     * Flag every collection / deck entry that pointed at $deletedId for the
     * user to review. The scryfall_cards row itself is kept so the user
     * still sees their card data (image, text) alongside the needs_review
     * flag — dropping it would cascade into FK issues and remove the info
     * the user needs to decide what to do with the stale reference.
     */
    private function markDeleted(string $deletedId): void
    {
        CollectionEntry::where('scryfall_id', $deletedId)
            ->update(['needs_review' => true]);

        DeckEntry::where('scryfall_id', $deletedId)
            ->update(['needs_review' => true]);
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
     * Rebuild scryfall_oracles from scryfall_cards. One row per oracle_id.
     *
     * - Picks the default representative printing with the same priority
     *   order the controller used to resolve on every query (minus the
     *   user-specific "owned wins" tier — default rep is user-agnostic).
     * - Aggregates printing_count, max_released_at, is_playtest_any, and
     *   excluded_from_catalog (set_type hard-exclude rolled up to oracle
     *   grain) so the search path doesn't need the sets LEFT JOIN anymore.
     * - Computes color_identity_bits / colors_bits via JSON_CONTAINS so
     *   parser color clauses collapse to a single integer op.
     *
     * Idempotent: TRUNCATE + INSERT SELECT. Run after handleMigrations so
     * post-merge scryfall_ids are reflected in default_scryfall_id.
     *
     * @return array{oracles: int}
     */
    public function syncOracleTable(): array
    {
        $excludedList = "'" . implode("','", self::INELIGIBLE_SET_TYPES) . "'";

        DB::statement('TRUNCATE TABLE scryfall_oracles');

        DB::statement(<<<SQL
            INSERT INTO scryfall_oracles (
                oracle_id,
                default_scryfall_id, default_set_code, default_collector_number,
                default_released_at, default_rarity,
                default_image_small, default_image_normal, default_image_large,
                name, layout, is_dfc, mana_cost, cmc, colors, color_identity,
                type_line, supertypes, types, subtypes,
                oracle_text, printed_text, power, toughness, loyalty,
                legalities, keywords, edhrec_rank, reserved,
                commander_game_changer, partner_scope,
                mana_cost_back, type_line_back, oracle_text_back, printed_text_back,
                image_small_back, image_normal_back, image_large_back,
                printing_count, max_released_at,
                is_playtest_any, excluded_from_catalog,
                is_transform, is_mdfc, is_flip, is_meld, is_split, is_leveler,
                color_identity_bits, colors_bits,
                last_synced_at, created_at, updated_at
            )
            SELECT
                reps.oracle_id,
                reps.scryfall_id, reps.set_code, reps.collector_number,
                reps.released_at, reps.rarity,
                reps.image_small, reps.image_normal, reps.image_large,
                reps.name, reps.layout, reps.is_dfc, reps.mana_cost, reps.cmc,
                reps.colors, reps.color_identity,
                reps.type_line, reps.supertypes, reps.types, reps.subtypes,
                reps.oracle_text, reps.printed_text,
                reps.power, reps.toughness, reps.loyalty,
                reps.legalities, reps.keywords, reps.edhrec_rank, reps.reserved,
                reps.commander_game_changer, reps.partner_scope,
                reps.mana_cost_back, reps.type_line_back,
                reps.oracle_text_back, reps.printed_text_back,
                reps.image_small_back, reps.image_normal_back, reps.image_large_back,
                aggs.printing_count, aggs.max_released_at,
                aggs.is_playtest_any, aggs.excluded_from_catalog,
                (reps.layout = 'transform'),
                (reps.layout = 'modal_dfc'),
                (reps.layout = 'flip'),
                (reps.layout = 'meld'),
                (reps.layout = 'split'),
                (reps.layout = 'leveler'),
                (COALESCE(JSON_CONTAINS(reps.color_identity, '"W"'), 0) * 1
                 + COALESCE(JSON_CONTAINS(reps.color_identity, '"U"'), 0) * 2
                 + COALESCE(JSON_CONTAINS(reps.color_identity, '"B"'), 0) * 4
                 + COALESCE(JSON_CONTAINS(reps.color_identity, '"R"'), 0) * 8
                 + COALESCE(JSON_CONTAINS(reps.color_identity, '"G"'), 0) * 16),
                (COALESCE(JSON_CONTAINS(reps.colors, '"W"'), 0) * 1
                 + COALESCE(JSON_CONTAINS(reps.colors, '"U"'), 0) * 2
                 + COALESCE(JSON_CONTAINS(reps.colors, '"B"'), 0) * 4
                 + COALESCE(JSON_CONTAINS(reps.colors, '"R"'), 0) * 8
                 + COALESCE(JSON_CONTAINS(reps.colors, '"G"'), 0) * 16),
                NOW(), NOW(), NOW()
            FROM (
                SELECT sc.*,
                       ROW_NUMBER() OVER (
                           PARTITION BY sc.oracle_id
                           ORDER BY
                               CASE WHEN sc.is_default_eligible THEN 0 ELSE 1 END,
                               sc.promo ASC,
                               COALESCE(sc.released_at, ms.released_at) DESC,
                               sc.set_code ASC,
                               -- collector_number can be '14p', '★123',
                               -- 'prerelease'… extract leading/embedded
                               -- digits via REGEXP_SUBSTR so strict-mode
                               -- INSERT doesn't trip on the cast. NULLs
                               -- sort last under ASC when paired with a
                               -- string fallback.
                               CAST(REGEXP_SUBSTR(sc.collector_number, '[0-9]+') AS UNSIGNED) ASC,
                               sc.collector_number ASC
                       ) AS rn
                FROM scryfall_cards sc
                LEFT JOIN sets ms ON ms.code = sc.set_code
            ) reps
            JOIN (
                SELECT
                    sc.oracle_id,
                    COUNT(*) AS printing_count,
                    MAX(COALESCE(sc.released_at, ms.released_at)) AS max_released_at,
                    MAX(CASE WHEN sc.is_playtest THEN 1 ELSE 0 END) AS is_playtest_any,
                    CASE WHEN SUM(
                        CASE WHEN (ms.set_type IS NULL
                                   OR ms.set_type NOT IN ({$excludedList})
                                   OR sc.is_playtest = 1)
                             THEN 1 ELSE 0 END
                    ) = 0 THEN 1 ELSE 0 END AS excluded_from_catalog
                FROM scryfall_cards sc
                LEFT JOIN sets ms ON ms.code = sc.set_code
                GROUP BY sc.oracle_id
            ) aggs ON aggs.oracle_id = reps.oracle_id
            WHERE reps.rn = 1
SQL
        );

        $count = (int) DB::table('scryfall_oracles')->count();
        Log::info("BulkSyncService::syncOracleTable — inserted {$count} oracles");

        return ['oracles' => $count];
    }
}
