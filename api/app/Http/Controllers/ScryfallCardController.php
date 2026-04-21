<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Services\BulkSyncService;
use App\Services\CardSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only access to the canonical Scryfall reference DB.
 *
 *   GET /api/scryfall-cards/search       — Scryfall-syntax search, oracle-grouped
 *   GET /api/scryfall-cards/printings    — all printings for an oracle_id
 *   GET /api/scryfall-cards/{scryfallId} — single card detail
 */
class ScryfallCardController extends Controller
{
    /** All Scryfall colour letters in canonical WUBRG order. */
    private const COLORS = ['W', 'U', 'B', 'R', 'G'];

    public function __construct(private CardSearchService $search) {}

    // ─────────────────────────────────────────────────────────────────────
    // Search (oracle-grouped)
    // ─────────────────────────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'              => 'sometimes|nullable|string|max:500',
            'per_page'       => 'sometimes|integer|min:1|max:100',
            'page'           => 'sometimes|integer|min:1',
            'deck_id'        => 'sometimes|integer|min:1',
            'owned_only'     => 'sometimes|boolean',
            'apply_format'   => 'sometimes|boolean',
            'apply_identity' => 'sometimes|boolean',
        ]);

        $userId = auth()->id();
        $perPage = (int) ($data['per_page'] ?? 60);
        $page = (int) ($data['page'] ?? 1);
        $ownedOnly = (bool) ($data['owned_only'] ?? false);
        $applyFormat = (bool) ($data['apply_format'] ?? true);
        $applyIdentity = (bool) ($data['apply_identity'] ?? true);

        $q = (string) ($data['q'] ?? '');

        // 2. Resolve deck context once (for ownership exclusion + filters
        //    applied to each parse pass).
        $excludeDeckId = null;
        $deck = null;
        if (! empty($data['deck_id'])) {
            $deckId = (int) $data['deck_id'];
            $deck = Deck::query()
                ->where('user_id', $userId)
                ->find($deckId);
            if ($deck === null) {
                // Don't leak existence of other users' decks.
                throw new NotFoundHttpException('Deck not found');
            }
            $excludeDeckId = $deck->id;
        }

        /**
         * Parse + deck-overlay the builder for a given parse-options
         * payload. Called twice at most: once with defaults applied,
         * and (if that returns zero results) once with defaults disabled
         * so name-searches that happen to match only hidden / playtest
         * cards still surface something. Returns the parsed bundle plus
         * the rendered inner WHERE string + bindings.
         *
         * @param  array{disable_defaults?: bool}  $opts
         * @return array{parsed: array<string, mixed>, innerSql: string, innerBindings: array<int, mixed>}
         */
        $buildForOpts = function (array $opts) use ($q, $deck, $applyFormat, $applyIdentity): array {
            $parsed = $this->search->search($q, $opts);
            /** @var \Illuminate\Database\Eloquent\Builder $builder */
            $builder = $parsed['builder'];

            if ($deck !== null) {
                if ($applyFormat && in_array($deck->format, CardSearchService::FORMAT_WHITELIST, true)) {
                    $builder->whereRaw(
                        "JSON_EXTRACT(legalities, '$.\"" . $deck->format . "\"') = 'legal'"
                    );
                }
                if ($applyIdentity) {
                    $deckIdentity = $this->parseDeckIdentity($deck->color_identity);
                    $builder->whereRaw(
                        '(JSON_LENGTH(color_identity) = 0 OR JSON_CONTAINS(?, color_identity))',
                        [json_encode($deckIdentity)],
                    );
                }
            }

            return [
                'parsed'        => $parsed,
                'innerSql'      => $this->extractWhereSql($builder),
                'innerBindings' => $builder->getBindings(),
            ];
        };

        $built = $buildForOpts([]);
        $parsed        = $built['parsed'];
        $innerSql      = $built['innerSql'];
        $innerBindings = $built['innerBindings'];
        $warnings      = $parsed['warnings'];
        $sort          = $parsed['sort'];

        // Guardrail: the window-wrapped query is a full-table scan of
        // scryfall_cards (~110k rows, ~30k oracles) when there's nothing
        // in WHERE. That sort takes 10+ minutes and saturates PHP-FPM.
        // Require at least ONE constraint — filter, deck_id, or owned_only.
        $hasConstraint =
            $innerSql !== ''
            || ! empty($data['deck_id'])
            || $ownedOnly;
        if (! $hasConstraint) {
            return response()->json([
                'current_page' => 1,
                'data'         => [],
                'last_page'    => 1,
                'per_page'     => $perPage,
                'total'        => 0,
                'warnings'     => array_merge($warnings, [
                    'Provide at least one filter (name, type, color, format, …) before searching.',
                ]),
            ]);
        }

        // 4. Build the window-wrapped query. ow.qty_owned is LEFT-JOINed
        //    so non-owned oracles still have a rep picked.
        $uid = (int) $userId;

        $ownershipJoin = "LEFT JOIN ("
            . "SELECT ce.scryfall_id, SUM(ce.quantity) AS qty_owned "
            . "FROM collection_entries ce "
            . "WHERE ce.user_id = {$uid} "
            . "GROUP BY ce.scryfall_id"
            . ") ow ON ow.scryfall_id = scryfall_cards.scryfall_id";

        // Always-on JOIN to sets so we can (a) hard-exclude art_series /
        // token / memorabilia / funny / vanguard / planechase / archenemy
        // printings, and (b) fall back to sets.released_at for the
        // representative-picking sort while scryfall_cards.released_at is
        // still NULL on rows that haven't been resynced through the new
        // mapping yet.
        //
        // The sets table exposes `name` and other columns that collide
        // with scryfall_cards (the CardSearchService emits unqualified
        // `name LIKE ?` on bare-text queries). Wrap the lookup in a
        // subquery that only projects the columns we actually need.
        $setJoin = "LEFT JOIN ("
            . "SELECT code, set_type, released_at AS set_released_at "
            . "FROM sets"
            . ") ms ON ms.code = scryfall_cards.set_code";
        $excluded = "'" . implode("','", BulkSyncService::INELIGIBLE_SET_TYPES) . "'";
        // Playtest sets live under set_type='funny' in our current data
        // (cmb1, cmb2). They're carved out so is:playtest can surface them;
        // the soft filter in CardSearchService hides them by default.
        $playtestExemption = empty(BulkSyncService::PLAYTEST_SET_CODES)
            ? ''
            : " OR scryfall_cards.set_code IN ('" . implode("','", BulkSyncService::PLAYTEST_SET_CODES) . "')";
        $setTypeFilter =
            "(ms.set_type IS NULL OR ms.set_type NOT IN ({$excluded}){$playtestExemption})";

        $outerOwnedFilter = $ownedOnly ? 'AND qty_owned > 0' : '';
        $orderBy = $this->search->buildOrderBy($sort);
        $offset = ($page - 1) * $perPage;

        // Sort resolution for the representative picker. Use
        // COALESCE(scryfall_cards.released_at, ms.set_released_at) so the
        // "newest printing wins" behaviour works on rows whose per-card
        // released_at column is still NULL from before the catalog
        // migration — sets.released_at is already populated by
        // scryfall:sync-sets.
        /**
         * Given a rendered inner WHERE + its bindings, build and run
         * both the window (data) and count SQLs. Extracted so the retry
         * path can reuse it verbatim.
         *
         * @param  array<int, mixed>  $bindings
         * @return array{rows: array<int, object>, total: int}
         */
        $runWindow = function (string $whereFragment, array $bindings) use (
            $ownershipJoin, $setJoin, $setTypeFilter, $outerOwnedFilter,
            $orderBy, $ownedOnly, $perPage, $offset
        ): array {
            $whereClause = $whereFragment === ''
                ? "WHERE {$setTypeFilter}"
                : "WHERE ({$whereFragment}) AND {$setTypeFilter}";

            $windowSql = "
                SELECT * FROM (
                    SELECT scryfall_cards.*,
                           ow.qty_owned,
                           COALESCE(scryfall_cards.released_at, ms.set_released_at) AS effective_released,
                           MAX(COALESCE(scryfall_cards.released_at, ms.set_released_at)) OVER (PARTITION BY scryfall_cards.oracle_id) AS oracle_max_released,
                           COUNT(*)                                                 OVER (PARTITION BY scryfall_cards.oracle_id) AS printing_count,
                           ROW_NUMBER() OVER (
                               PARTITION BY scryfall_cards.oracle_id
                               ORDER BY
                                   CASE WHEN ow.qty_owned > 0 THEN 0 ELSE 1 END,
                                   CASE WHEN ow.qty_owned > 0 THEN NULL
                                        WHEN scryfall_cards.is_default_eligible THEN 0 ELSE 1 END,
                                   -- Within the ineligible tier, non-promo
                                   -- printings beat promo-stamped ones.
                                   -- Covers cards that only exist in an
                                   -- eternal/box/masterpiece set plus a
                                   -- promo print — the non-promo wins
                                   -- regardless of set_code alphabet.
                                   scryfall_cards.promo ASC,
                                   COALESCE(scryfall_cards.released_at, ms.set_released_at) DESC,
                                   scryfall_cards.set_code ASC,
                                   CAST(scryfall_cards.collector_number AS UNSIGNED) ASC,
                                   scryfall_cards.collector_number ASC
                           ) AS rn
                    FROM scryfall_cards
                    {$ownershipJoin}
                    {$setJoin}
                    {$whereClause}
                ) x
                WHERE rn = 1 {$outerOwnedFilter}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ";

            $countSql = "
                SELECT COUNT(*) AS n FROM (
                    SELECT DISTINCT scryfall_cards.oracle_id
                    FROM scryfall_cards
                    {$ownershipJoin}
                    {$setJoin}
                    {$whereClause}
                    " . ($ownedOnly ? "AND ow.qty_owned > 0" : "") . "
                ) t
            ";

            $total = (int) DB::selectOne($countSql, $bindings)->n;
            $rows = $total > 0
                ? DB::select($windowSql, array_merge($bindings, [$perPage, $offset]))
                : [];
            return ['rows' => $rows, 'total' => $total];
        };

        $result = $runWindow($innerSql, $innerBindings);
        $rows = $result['rows'];
        $total = $result['total'];

        // Retry without the default-hidden filters when the first pass
        // found nothing — lets a name-only search surface playtest / hidden
        // -type cards when they're the ONLY thing that matches. Caller can
        // still get the default-hidden exclusion by explicitly phrasing
        // their query (`t:creature Slivdrazi` wouldn't match even on retry
        // since the type constraint stays on).
        if ($total === 0 && ($parsed['defaults_applied'] ?? false)) {
            $retryBuilt = $buildForOpts(['disable_defaults' => true]);
            $retryInnerSql = $retryBuilt['innerSql'];
            $retryBindings = $retryBuilt['innerBindings'];
            $retryResult = $runWindow($retryInnerSql, $retryBindings);
            if ($retryResult['total'] > 0) {
                $rows = $retryResult['rows'];
                $total = $retryResult['total'];
                $warnings[] = 'Normally-hidden cards shown because no other results matched.';
            }
        }

        // 6. Oracle-level ownership aggregates for the paged slice.
        $oracleIds = array_values(array_filter(array_map(fn ($r) => $r->oracle_id, $rows)));
        $ownership = $this->ownershipMap($oracleIds, $userId, $excludeDeckId);

        // 7. Reshape to match the existing present() output, then attach
        //    ownership fields plus grouping metadata.
        $data = array_map(function ($r) use ($ownership) {
            $own = $ownership[$r->oracle_id] ?? ['owned' => 0, 'available' => 0, 'wanted_by_others' => 0];
            return $this->presentRow($r, $own);
        }, $rows);

        $paginator = new LengthAwarePaginator(
            $data,
            $total,
            $perPage,
            $page,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ],
        );

        $payload = $paginator->toArray();
        $payload['warnings'] = $warnings;

        return response()->json($payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Printings
    // ─────────────────────────────────────────────────────────────────────

    public function printings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'oracle_id' => 'required|string|size:36',
        ]);

        $userId = auth()->id();
        $oracleId = $data['oracle_id'];

        // Same hard exclusion as search.
        $excluded = "'" . implode("','", BulkSyncService::INELIGIBLE_SET_TYPES) . "'";
        $playtestExemption = empty(BulkSyncService::PLAYTEST_SET_CODES)
            ? ''
            : " OR sc.set_code IN ('" . implode("','", BulkSyncService::PLAYTEST_SET_CODES) . "')";

        $rows = DB::select("
            SELECT sc.*,
                   ms.name         AS set_name,
                   ms.icon_svg_uri AS icon_svg_uri,
                   ms.released_at  AS set_released_at
            FROM scryfall_cards sc
            LEFT JOIN sets ms ON ms.code = sc.set_code
            WHERE sc.oracle_id = ?
              AND (ms.set_type IS NULL OR ms.set_type NOT IN ({$excluded}){$playtestExemption})
            ORDER BY COALESCE(sc.released_at, ms.released_at) DESC, sc.set_code ASC
        ", [$oracleId]);

        if (empty($rows)) {
            return response()->json(['data' => []]);
        }

        $sids = array_map(fn ($r) => $r->scryfall_id, $rows);

        // Per-printing nonfoil / foil ownership.
        $ownedRows = CollectionEntry::query()
            ->where('user_id', $userId)
            ->whereIn('scryfall_id', $sids)
            ->selectRaw('scryfall_id, foil, SUM(quantity) AS qty')
            ->groupBy('scryfall_id', 'foil')
            ->get();

        $owned = [];
        foreach ($ownedRows as $o) {
            $key = $o->foil ? 'foil' : 'nonfoil';
            $owned[$o->scryfall_id][$key] = (int) $o->qty;
        }

        // Per-printing nonfoil / foil committed (via physical_copy_id).
        $committedRows = DeckEntry::query()
            ->join('collection_entries as ce', 'ce.id', '=', 'deck_entries.physical_copy_id')
            ->where('ce.user_id', $userId)
            ->whereIn('ce.scryfall_id', $sids)
            ->selectRaw('ce.scryfall_id AS sid, ce.foil, COUNT(*) AS used')
            ->groupBy('ce.scryfall_id', 'ce.foil')
            ->get();

        $committed = [];
        foreach ($committedRows as $c) {
            $key = $c->foil ? 'foil' : 'nonfoil';
            $committed[$c->sid][$key] = (int) $c->used;
        }

        $out = array_map(function ($r) use ($owned, $committed) {
            $o = $owned[$r->scryfall_id] ?? [];
            $c = $committed[$r->scryfall_id] ?? [];
            $nonfoil = (int) ($o['nonfoil'] ?? 0);
            $foil    = (int) ($o['foil']    ?? 0);
            $usedNonfoil = (int) ($c['nonfoil'] ?? 0);
            $usedFoil    = (int) ($c['foil']    ?? 0);

            return [
                'scryfall_id'         => $r->scryfall_id,
                'set_code'            => $r->set_code,
                'set_name'            => $r->set_name,
                'icon_svg_uri'        => $r->icon_svg_uri,
                'collector_number'    => $r->collector_number,
                // Fall back to the set's released_at until the next bulk
                // sync populates scryfall_cards.released_at directly.
                'released_at'         => $r->released_at ?? $r->set_released_at,
                'rarity'              => $r->rarity,
                'image_small'         => $r->image_small,
                'image_normal'        => $r->image_normal,
                'image_large'         => $r->image_large,
                'image_small_back'    => $r->image_small_back,
                'image_normal_back'   => $r->image_normal_back,
                'image_large_back'    => $r->image_large_back,
                'is_default_eligible' => (bool) $r->is_default_eligible,
                'promo'               => (bool) $r->promo,
                'variation'           => (bool) $r->variation,
                'ownership' => [
                    'nonfoil'           => $nonfoil,
                    'foil'              => $foil,
                    'available_nonfoil' => max(0, $nonfoil - $usedNonfoil),
                    'available_foil'    => max(0, $foil    - $usedFoil),
                ],
            ];
        }, $rows);

        return response()->json(['data' => $out]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Show (unchanged external contract; now uses oracle-aggregated ownership)
    // ─────────────────────────────────────────────────────────────────────

    public function show(ScryfallCard $scryfallCard): JsonResponse
    {
        $scryfallCard->load('tags');

        $ownership = $this->ownershipMap([$scryfallCard->oracle_id], auth()->id());

        return response()->json($this->present($scryfallCard, $ownership));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oracle-aggregated ownership
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Keyed by oracle_id. Aggregates ownership across every printing of the
     * oracle. `wanted_by_others` = sum of deck_entries.quantity in decks
     * OTHER than $excludeDeckId.
     *
     * @param  array<int, string>  $oracleIds
     * @return array<string, array{owned: int, available: int, wanted_by_others: int}>
     */
    private function ownershipMap(array $oracleIds, ?int $userId, ?int $excludeDeckId = null): array
    {
        if ($userId === null || empty($oracleIds)) {
            return [];
        }

        // 1. Owned — SUM(quantity) per oracle across all its printings.
        $owned = CollectionEntry::query()
            ->join('scryfall_cards as sc', 'sc.scryfall_id', '=', 'collection_entries.scryfall_id')
            ->where('collection_entries.user_id', $userId)
            ->whereIn('sc.oracle_id', $oracleIds)
            ->selectRaw('sc.oracle_id, SUM(collection_entries.quantity) AS qty')
            ->groupBy('sc.oracle_id')
            ->pluck('qty', 'oracle_id');

        // 2. Committed — one deck-slot per physical copy.
        $committed = DeckEntry::query()
            ->join('collection_entries as ce', 'ce.id', '=', 'deck_entries.physical_copy_id')
            ->join('scryfall_cards as sc', 'sc.scryfall_id', '=', 'ce.scryfall_id')
            ->where('ce.user_id', $userId)
            ->whereIn('sc.oracle_id', $oracleIds)
            ->selectRaw('sc.oracle_id, COUNT(*) AS used')
            ->groupBy('sc.oracle_id')
            ->pluck('used', 'oracle_id');

        // 3. Wanted-by-others — deck slots in user's OTHER decks for the same
        //    oracle (not tied to physical copies; zone slots count).
        $wantedQ = DeckEntry::query()
            ->join('decks as d', 'd.id', '=', 'deck_entries.deck_id')
            ->join('scryfall_cards as sc', 'sc.scryfall_id', '=', 'deck_entries.scryfall_id')
            ->where('d.user_id', $userId)
            ->whereIn('sc.oracle_id', $oracleIds);
        if ($excludeDeckId !== null) {
            $wantedQ->where('d.id', '!=', $excludeDeckId);
        }
        $wanted = $wantedQ
            ->selectRaw('sc.oracle_id, SUM(deck_entries.quantity) AS want')
            ->groupBy('sc.oracle_id')
            ->pluck('want', 'oracle_id');

        $out = [];
        foreach ($oracleIds as $oid) {
            $o = (int) ($owned[$oid] ?? 0);
            $c = (int) ($committed[$oid] ?? 0);
            $out[$oid] = [
                'owned'            => $o,
                'available'        => max(0, $o - $c),
                'wanted_by_others' => (int) ($wanted[$oid] ?? 0),
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presentation helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Shape a raw stdClass row (from the window query) into the search
     * response. JSON columns come back as strings — decode them here.
     *
     * @param  array{owned: int, available: int, wanted_by_others: int}  $own
     * @return array<string, mixed>
     */
    private function presentRow(object $r, array $own): array
    {
        $out = [
            'scryfall_id'      => $r->scryfall_id,
            'oracle_id'        => $r->oracle_id,
            'name'             => $r->name,
            'set_code'         => $r->set_code,
            'collector_number' => $r->collector_number,
            'released_at'      => $r->released_at,
            'rarity'           => $r->rarity,
            'layout'           => $r->layout,
            'is_dfc'           => (bool) $r->is_dfc,
            'mana_cost'        => $r->mana_cost,
            'cmc'              => $r->cmc !== null ? (float) $r->cmc : null,
            'colors'           => $this->decodeJson($r->colors),
            'color_identity'   => $this->decodeJson($r->color_identity),
            'type_line'        => $r->type_line,
            'supertypes'       => $this->decodeJson($r->supertypes),
            'types'            => $this->decodeJson($r->types),
            'subtypes'         => $this->decodeJson($r->subtypes),
            'oracle_text'      => $r->oracle_text,
            'printed_text'     => $r->printed_text,
            'power'            => $r->power,
            'toughness'        => $r->toughness,
            'loyalty'          => $r->loyalty,
            'legalities'       => $this->decodeJson($r->legalities),
            'keywords'         => $this->decodeJson($r->keywords),
            'edhrec_rank'      => $r->edhrec_rank !== null ? (int) $r->edhrec_rank : null,
            'reserved'         => (bool) $r->reserved,
            'commander_game_changer' => (bool) $r->commander_game_changer,
            'partner_scope'    => $r->partner_scope,
            'image_small'      => $r->image_small,
            'image_normal'     => $r->image_normal,
            'image_large'      => $r->image_large,
            'printing_count'   => (int) $r->printing_count,
            'owned_count'      => $own['owned'],
            'available_count'  => $own['available'],
            'wanted_by_others' => $own['wanted_by_others'],
        ];

        if ($r->is_dfc) {
            $out['mana_cost_back']    = $r->mana_cost_back;
            $out['type_line_back']    = $r->type_line_back;
            $out['oracle_text_back']  = $r->oracle_text_back;
            $out['printed_text_back'] = $r->printed_text_back;
            $out['image_small_back']  = $r->image_small_back;
            $out['image_normal_back'] = $r->image_normal_back;
            $out['image_large_back']  = $r->image_large_back;
        }

        // oracle_tags via one extra query — minor cost vs. the N+1 alternative.
        $out['oracle_tags'] = DB::table('card_oracle_tags')
            ->where('oracle_id', $r->oracle_id)
            ->pluck('tag')
            ->all();

        return $out;
    }

    /**
     * Legacy show() shaping — kept compatible with the existing front-end
     * contract for single-card detail. Oracle-level ownership.
     *
     * @param  array<string, array{owned: int, available: int, wanted_by_others: int}>  $ownership
     * @return array<string, mixed>
     */
    private function present(ScryfallCard $card, array $ownership): array
    {
        $own = $ownership[$card->oracle_id] ?? ['owned' => 0, 'available' => 0, 'wanted_by_others' => 0];

        $out = [
            'scryfall_id'      => $card->scryfall_id,
            'oracle_id'        => $card->oracle_id,
            'name'             => $card->name,
            'set_code'         => $card->set_code,
            'collector_number' => $card->collector_number,
            'rarity'           => $card->rarity,
            'layout'           => $card->layout,
            'is_dfc'           => $card->is_dfc,
            'mana_cost'        => $card->mana_cost,
            'cmc'              => $card->cmc !== null ? (float) $card->cmc : null,
            'colors'           => $card->colors,
            'color_identity'   => $card->color_identity,
            'type_line'        => $card->type_line,
            'supertypes'       => $card->supertypes,
            'types'            => $card->types,
            'subtypes'         => $card->subtypes,
            'oracle_text'      => $card->oracle_text,
            'printed_text'     => $card->printed_text,
            'power'            => $card->power,
            'toughness'        => $card->toughness,
            'loyalty'          => $card->loyalty,
            'legalities'       => $card->legalities,
            'keywords'         => $card->keywords,
            'edhrec_rank'      => $card->edhrec_rank,
            'reserved'         => $card->reserved,
            'image_small'      => $card->image_small,
            'image_normal'     => $card->image_normal,
            'image_large'      => $card->image_large,
            'oracle_tags'      => $card->relationLoaded('tags')
                ? $card->tags->pluck('tag')->values()->all()
                : [],
            'owned_count'      => $own['owned'],
            'available_count'  => $own['available'],
            'wanted_by_others' => $own['wanted_by_others'],
        ];

        if ($card->is_dfc) {
            $out['mana_cost_back']    = $card->mana_cost_back;
            $out['type_line_back']    = $card->type_line_back;
            $out['oracle_text_back']  = $card->oracle_text_back;
            $out['printed_text_back'] = $card->printed_text_back;
            $out['image_small_back']  = $card->image_small_back;
            $out['image_normal_back'] = $card->image_normal_back;
            $out['image_large_back']  = $card->image_large_back;
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Small utilities
    // ─────────────────────────────────────────────────────────────────────

    private function decodeJson($v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);
        return $decoded === null ? [] : $decoded;
    }

    /**
     * Extract the WHERE fragment from an Eloquent builder so we can splice
     * it into the window-wrapped raw SQL. Returns an empty string when the
     * builder has no WHERE clauses. Bindings are still accessible via
     * getBindings() on the original builder.
     */
    private function extractWhereSql(\Illuminate\Database\Eloquent\Builder $builder): string
    {
        $sql = $builder->toSql(); // "select * from `scryfall_cards` where …"
        if (stripos($sql, 'where') === false) {
            return '';
        }
        $wherePart = substr($sql, stripos($sql, 'where') + 5);
        // Strip any trailing ORDER/LIMIT/OFFSET just in case.
        foreach (['order by', 'limit', 'offset'] as $clause) {
            $i = stripos($wherePart, ' ' . $clause);
            if ($i !== false) {
                $wherePart = substr($wherePart, 0, $i);
            }
        }
        // Outer FROM uses the un-aliased table name, so qualified
        // `scryfall_cards.*` refs (from whereExists correlations) resolve
        // directly and unqualified refs pick the sole scryfall_cards table.
        return trim($wherePart);
    }

    /**
     * Convert decks.color_identity (varchar like "WUB") to the JSON-array
     * form we compare against scryfall_cards.color_identity (also sorted
     * WUBRG, so set-equality compares element-wise).
     *
     * @return array<int, string>
     */
    private function parseDeckIdentity(?string $ci): array
    {
        if ($ci === null || $ci === '') {
            return [];
        }
        $upper = strtoupper($ci);
        $letters = array_values(array_filter(
            array_unique(str_split($upper)),
            fn ($c) => in_array($c, self::COLORS, true),
        ));
        $order = array_flip(self::COLORS);
        usort($letters, fn ($a, $b) => $order[$a] <=> $order[$b]);
        return $letters;
    }
}
