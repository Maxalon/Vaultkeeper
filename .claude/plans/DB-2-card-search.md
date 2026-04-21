# DB-2 — Card Catalog

A full Scryfall-syntax card search backed by the local `scryfall_cards` table, surfaced as a standalone Catalog view with oracle-grouped results, per-oracle ownership counts, per-printing selection, virtualized infinite-scroll grid, and a deckbuilder-ready drop target. Unblocks DB-3 (deckbuilder tab system + deck view).

## Context

PR #16 landed a basic `GET /api/scryfall-cards/search` (name LIKE + format + color_identity + commander URL params) and a thin `SyntaxSearch.vue` input component used only in the collection top bar. PR #19 removed `user_cards` and repointed both `collection_entries.scryfall_id` and `deck_entries.scryfall_id` at `scryfall_cards.scryfall_id` (both FKs ON UPDATE CASCADE, implicit RESTRICT on delete). DB-1 shipped `decks`, `deck_entries`, `DeckLegalityService`, and the Scryfall bulk sync pipeline. Two things block DB-3: (1) a real card catalog UI to browse paper cards, and (2) a search engine rich enough to drive a deckbuilder add-card flow. DB-2 delivers both, plus the schema additions the catalog queries depend on.

## Decisions locked in during planning

1. **Drop legacy URL params.** `format`, `color_identity`, `commander` on `/api/scryfall-cards/search` have zero callers (verified: no frontend code, no tests, no backend services). Remove cleanly. `deck_id` subsumes.
2. **`deck_id` uses cached deck fields, not DeckLegalityService.** Load `decks.format` (enum) and `decks.color_identity` (varchar(5) "WUB"-style string, already cached via `DeckController::recomputeColorIdentity` at `api/app/Http/Controllers/DeckController.php:189-204`). Apply two SQL constraints: `legalities.{format} = 'legal'` and `JSON_CONTAINS(deck_identity_array, card.color_identity)`. No service call.
3. **Scryfall syntax grammar:** `t:legendary creature` = `JSON_OVERLAPS(supertypes, Legendary) AND name LIKE '%creature%'`. `!"Lightning Bolt"` = exact name. `"Lightning Bolt"` = `name LIKE '%Lightning Bolt%'` (literal with spaces). `Lightning Bolt` = two tokens, both name LIKE. All searches case-insensitive (rely on utf8mb4_unicode_ci collation; verify at migration).
4. **Parser normalizes input case.** Title Case for types/supertypes/subtypes/keywords (matches stored JSON array values). UPPER for colors. Required for `JSON_OVERLAPS` and multi-valued index hits.
5. **`warnings` at top level of response**, not per-card.
6. **`order:` + `direction:` supported.** Values: `name`, `cmc`/`mv`, `rarity`, `power`, `toughness`, `set`, `released`, `edhrec`. `direction:asc|desc`. Default: `name ASC`. Price-based orders (`usd`, `eur`, `tix`) → warnings. `order:color` cut.
7. **Sort specifics.** `order:released` → `MAX(released_at)` across an oracle's printings (stable regardless of representative). `order:set` → representative's `set_code` alphabetically. `order:rarity` and rarity comparisons use `FIELD(rarity, 'common','uncommon','rare','mythic')`. `order:power` / `order:toughness` use `CAST(... AS SIGNED)` with `*`/`X` treated as `0`, nulls last. `order:edhrec` → `edhrec_rank IS NULL ASC, edhrec_rank ASC` (nulls last).
8. **Unknown format in `f:`** → add warning, drop constraint (don't zero results, don't error).
9. **`is:reprint` dropped.** Add to warnings alongside other unsupported operators.
10. **Oracle grouping for results.** Search returns one row per `oracle_id`. Per-printing detail via separate endpoint opened on demand.
11. **Representative printing selection** (per oracle):
    - User owns ≥1 copy of any printing → most recently released OWNED printing.
    - Else → most recently released printing where `is_default_eligible = true`.
12. **`is_default_eligible` precomputed at sync** via `BulkSyncService::deriveDefaultEligible($card)` — a pure function of the raw Scryfall bulk object. ALL of these must hold for a printing to be eligible as the default representative:
    - `nonfoil === true` (rules out foil-only alt treatments)
    - `frame_effects` contains none of `BulkSyncService::ALT_FRAME_EFFECTS` = `{showcase, extendedart, etched, nyxtouched, colorshifted, inverted, shatteredglass}`. The check is a *denylist*, not an allowlist, because many frame_effects are intrinsic to a card's nature (`enchantment`, `legendary`, `snow`, `spree`, `lesson`, `tombstone`, `devoid`, `miracle`, `companion`, the various DFC layout markers, …) — treating them as alt treatments would falsely disqualify every Theros enchantment, every legendary creature, every snow permanent, etc.
    - `border_color ∈ {black, white}` (`ALLOWED_BORDER_COLORS`). Excludes `borderless` (alt-art), `silver` (Un-sets), `gold` (World Championship decks), `yellow` (Alchemy digital / rebalanced frame).
    - `promo === false` (catches promo-stamped printings regardless of set_type)
    - `variation === false` (alternate versions of the same collector number)
    - `oversized === false` (oversized Plane / commander cards)
    - `set_type` is not in `{memorabilia, funny, token, minigame, box, masterpiece, from_the_vault, premium_deck, spellbook, eternal, promo}` — premium / special-product sets whose printings can look visually normal (Secret Lair especially — `box` set_type) but should never be the default representative when a real expansion printing exists. If an oracle only has SLD/masterpiece/etc. printings, every printing ties on eligibility and the released-date sort picks the newest, so SLD-only cards still surface correctly.

    Window-query sort adds `CAST(collector_number AS UNSIGNED) ASC, collector_number ASC` as final tiebreakers so ties among ineligible printings in the same set deterministically prefer lower-numbered (main-set) prints over higher-numbered booster-fun variants.

    The controller's HARD-exclusion list `BulkSyncService::INELIGIBLE_SET_TYPES` is a superset (`… + art_series, vanguard, planechase, archenemy`) and operates at query time — those rows never reach the catalog at all, so per-card eligibility doesn't need to cover them.
13. **Ownership counts aggregated at oracle level** in search response: `owned_count`, `available_count`, `wanted_by_others` are sums across all printings of the oracle. **Per-printing + foil-split ownership** comes from the separate printings endpoint only.
14. **`wanted_by_others` semantic:** `SUM(deck_entries.quantity)` across user's OTHER decks (excluding the current `deck_id` if provided). Counts zone-based slots. `wanted` enum demand-signal is a separate feature.
15. **`o:` vs `fo:` are distinct.** `o:` / `oracle:` searches `oracle_text` + `oracle_text_back` (canonical text; reminder text may or may not be included — verify at implementation). `fo:` / `fulloracle:` searches the new `printed_text` + `printed_text_back` columns (includes reminder text), union with `oracle_text` as a fallback for cards missing `printed_text`.
16. **Drop fulltext index.** Use `LIKE` for `o:`/`fo:`/name — 30k paper cards, local DB, simple + predictable.
17. **Color family operators:**
    - `c:` / `color:` → superset on `colors` by default. `c:wg`, `c=wg`, `c<=wg`, `c>=wg`, `c:c` (colorless via `JSON_LENGTH=0`), `c:m` (multicolor via `JSON_LENGTH>=2`).
    - `ci:` / `id:` / `identity:` / `color_identity:` → full Scryfall semantics on `color_identity`. Superset by default. Supports `=`, `<=`, `>=`, `!=` comparators. Same logic as `c:` but on `color_identity`. Colorless / multicolor shortcuts apply.
    - `commander:` → **subset only**, no comparators. `commander:wu` means "card can go in a WU deck": `JSON_LENGTH(color_identity) = 0 OR JSON_CONTAINS(:target_array, color_identity)`. Colorless always passes.
18. **Cleanup strategy (paper-only):** dry-run preflight before truncate. If any `collection_entries` or `deck_entries` reference a non-paper card, halt and report blockers. If zero, truncate `scryfall_cards` + `scryfall_card_raws` (FK checks disabled for the moment), refetch bulk, refetch oracle tags, then run orphan cleanup on `card_oracle_tags`.
19. **`CardSearchService` holds sort logic via `applySort()`.** No new Repository layer; consistent with existing service-per-feature pattern.
20. **Multi-word type/subtype handling.** Scryfall has multi-word subtypes (`Time Lord`, potentially others as new sets release). Sync a local type catalog from Scryfall's `/catalog/{creature-types,planeswalker-types,land-types,artifact-types,enchantment-types,spell-types,supertypes,card-types}` endpoints into a new `mtg_type_catalog(category, name)` table. At type-line parse time, the parser greedily matches multi-word entries from that catalog before splitting on whitespace. Auto-updating, future-proof.
21. **Frontend: new `CatalogPanel.vue` + `catalog.js` Pinia store.** Independent of `collection.js`. Self-contained for drop-in use in DB-3's tab system. Temporary `/catalog` route added for DB-2 testing with a `TODO: remove in DB-3` comment.
22. **Default view: full-image grid via new `CardTile.vue`; strip view via new `CatalogStrip.vue`.** Grid/strip toggle persists in `catalog.displayMode`. The strip renderer is a catalog-specific sibling of the collection's `CardStrip.vue` — both consume `composables/useColorSkeleton.js` (the extracted WUBRG palette + skeleton-style helper) so the two agree on colors without inheriting each other's Mode A/B and hover-peek complexity. Strip view packs results into columns (same layout as collection `CardListPanel`) driven by the global `--card-width` / `--strip-height` settings so density adjustments apply to both.
23. **Rendering via native `content-visibility: auto`, not JS virtualization.** 3 discrete size levels (S/M/L). Image picked via `<img srcset>` from `image_small`/`image_normal`/`image_large`. Every card tile gets `content-visibility: auto` + `contain-intrinsic-size` so off-screen cards skip layout/paint — identical effective result to virtualization for a 3k-card page, with no library dep and simpler code. The `@tanstack/vue-virtual` upgrade stays captured in the perf-review follow-up issue in case real data shows a regression.
24. **Infinite scroll, no hard cap.** Result count displayed next to search input: `3,247 cards found`. Pagination: default 60/page, max 100.
25. **`CardTile.vue` visuals:** card image only. Accent border. Ownership shine (green if owned+available, blue if owned+all-committed, red if unowned). Gold badge top-left = `owned_count`. Red badge top-right = `wanted_by_others`. Hover shows `DfcPopover` for DFC cards.
26. **`DfcPopover.vue`** is a new shared primitive. Wired into CatalogPanel in DB-2; a follow-up issue wires it into CardStrip.
27. **Detail sidebar refactor:** extract a shared `<CardDetailBody>` primitive from the existing `DetailSidebar.vue`. Collection-side `DetailSidebar` becomes a thin wrapper composing `CardDetailBody` + collection-specific "Your Copies" and "Wanted by decks" blocks. New `CatalogDetailSidebar.vue` composes `CardDetailBody` + a "Printings" section.
28. **Printing selector UI:** in `CatalogDetailSidebar.vue`. Click a CardTile → open sidebar with Printings section. Each row shows set icon, set code, collector number, released_at, and per-printing ownership (nonfoil count, foil count, with available subtotals). Radio selection updates the tile's representative printing (catalog-local state).
29. **Printing persistence:** not persisted in the Catalog. Deck view and collection view persist printings by their own mechanisms (scryfall_id on the entries).
30. **Deckbuilder add-card flow (catalog → deck):** DB-2 emits intent only. Clicking a tile with `deckId` prop set shows inline popover with `+ Main / + Side / + Maybe`. Click emits `add-to-deck` event with `{ scryfall_id, zone }`. DB-3 wires the receiver.
31. **Drag-and-drop:** tiles are draggable. Payload `application/json`: `{ oracle_id, scryfall_id, source: 'catalog' }`. Browser default drag-ghost. DB-3 deck view handles drop.
32. **Invalid/other-user `deck_id` → 404.** Don't leak existence.
33. **Visible deck-filter pills** when `deck_id` set. Each applied filter (format, color identity) renders as a togglable pill so users can toggle off implicit constraints.
34. **Empty-state + warnings UX:** when results empty AND warnings present, empty-state hints at the unsupported operator. When results non-empty AND warnings present, inline dismissible banner above results.
35. **Scope cuts from the original plan:** fulltext index dropped. `is:reprint` dropped. `order:color` dropped. `mana_cost_normalized` not added (`m:` uses LIKE on `mana_cost`). Foil column on deck_entries deferred to follow-up issue.
36. **Pagination is manual, not `Builder::paginate()`.** The oracle-grouping window wrapper breaks Laravel's automatic COUNT — it would count printings, not oracles. The controller runs two queries: `SELECT COUNT(DISTINCT oracle_id)` off the filtered inner query for `total`, plus the paged window query for `data`, then assembles a `LengthAwarePaginator` by hand.
37. **Deck-filter pill state is transported as query params.** Frontend sends `apply_format=false` / `apply_identity=false` when a pill is toggled off. Backend defaults both to true when `deck_id` is set; each constraint emits only when its toggle is true. When `deck_id` is absent both params are ignored.
38. **Parser supports group-level negation.** `-(...)` and `NOT (...)` wrap the whole subtree's generated SQL in `NOT (...)`. Implemented by carrying a `negated` flag through the AST node, flipped by either a leading `-`/`NOT` or a negated parent group. Precedence: `NOT` > `AND` (implicit) > `OR`.
39. **Set icons come from `mtg_sets.icon_svg_uri` via the printings endpoint.** Already synced by `scryfall:sync-sets`. The printings response includes `icon_svg_uri` per row; the frontend renders it directly — no resolver function needed.
40. **Name / oracle_text / printed_text comparisons do NOT wrap columns in `LOWER()`.** Column collation is `utf8mb4_unicode_ci` (case-insensitive by default). Wrapping breaks sargability. Use bare `column LIKE ?` with the value lowercased only for consistency of the bound param.
41. **`f:` / `banned:` / `restricted:` validate format against a whitelist before emission.** Source of truth: the `format` enum on `decks` (currently: commander, brawler, standard, pioneer, modern, legacy, vintage, pauper). Unknown format → warning + drop (defense-in-depth even though the enum is DB-controlled).
42. **`owned_only` applies in the OUTER scope, not the inner WHERE.** The LEFT JOIN to the ownership subquery already exposes `qty_owned` on every printing. `owned_only=true` becomes `WHERE rn=1 AND qty_owned > 0` on the outer query, so the representative picker still sees every printing when deciding which one to surface.
43. **Default-hidden card types.** Scheme, Plane, Phenomenon, Vanguard, Conspiracy, and Dungeon are hidden by default. Users can surface them by specifying `t:<type>` (any one of those is enough to un-hide that type; others stay hidden), or with a bang-exact match (`!"…"`), which disables all default-hidden filters entirely. The check compares JSON overlap on `scryfall_cards.types`. This replaces the old approach of hard-excluding `vanguard`/`planechase`/`archenemy` at the set-type layer — those sets are now surfaceable via type queries.
44. **`is:playtest` + default playtest hide.** Mystery Booster Playtest sets (cmb1, cmb2) currently live under `set_type='funny'` in our sets sync — same bucket as Un-sets, which we keep hard-excluded. A small set-code carve-out (`BulkSyncService::PLAYTEST_SET_CODES`) exempts those two codes from the hard filter, and a soft filter hides them by default. `is:playtest` surfaces them (matches `set_type='playtest'` OR set_code in the list). Bang-exact match also disables the hide. When Scryfall's current `playtest` set_type propagates through our sets sync, `PLAYTEST_SET_CODES` can shrink to empty.
45. **A-prefix sort normalisation.** Alchemy-rebalanced cards (e.g. `A-Blessed Hippogriff`) clump at the top of name-ordered results because their displayed name starts with `A-`. The ORDER BY uses `IF(name LIKE 'A-%', SUBSTRING(name, 3), name)` as the sort key so they sort next to their non-rebalanced counterparts; display name is unchanged.

---
## Part 1 — Schema additions to `scryfall_cards`

Migration: `api/database/migrations/YYYY_MM_DD_add_catalog_columns_to_scryfall_cards.php`

New columns:
- `supertypes` JSON nullable — e.g. `["Legendary","Snow"]`
- `types` JSON nullable — e.g. `["Creature"]`
- `subtypes` JSON nullable — e.g. `["Elf","Warrior"]` or `["Time Lord"]`
- `released_at` DATE nullable, indexed
- `promo` BOOLEAN default false
- `variation` BOOLEAN default false
- `set_type` VARCHAR(30) nullable
- `oversized` BOOLEAN default false
- `is_default_eligible` BOOLEAN default false, indexed
- `printed_text` TEXT nullable
- `printed_text_back` TEXT nullable

Indexes (MySQL 8.4 supports multi-valued JSON indexes):
```sql
ALTER TABLE scryfall_cards
  ADD INDEX idx_supertypes ((CAST(supertypes AS CHAR(40) ARRAY))),
  ADD INDEX idx_types      ((CAST(types      AS CHAR(40) ARRAY))),
  ADD INDEX idx_subtypes   ((CAST(subtypes   AS CHAR(40) ARRAY))),
  ADD INDEX idx_keywords   ((CAST(keywords   AS CHAR(40) ARRAY))); -- skip if exists
ALTER TABLE scryfall_cards ADD INDEX idx_released_at (released_at);
```

`oracle_id` index already exists per prior audit.

**Preflight (run before migration):** confirm DB collation is `utf8mb4_unicode_ci` (case-insensitive). If different, add `CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` or switch string comparisons to `LOWER(...) = LOWER(...)` explicitly.

Update `api/app/Models/ScryfallCard.php`:
- Extend `$fillable` with the 11 new columns.
- `$casts`: `supertypes`, `types`, `subtypes` → `array`. `promo`, `variation`, `oversized`, `is_default_eligible` → `boolean`. `released_at` → `date`.

### New table: `mtg_type_catalog`

Migration: `api/database/migrations/YYYY_MM_DD_create_mtg_type_catalog_table.php`

```
id               bigint PK
category         varchar(40)  -- 'supertype', 'card_type', 'creature_subtype',
                              -- 'planeswalker_subtype', 'land_subtype',
                              -- 'artifact_subtype', 'enchantment_subtype',
                              -- 'spell_subtype'
name             varchar(80)
is_multi_word    boolean (generated: name LIKE '% %')
created_at, updated_at
UNIQUE (category, name)
INDEX (is_multi_word)
```

New model `api/app/Models/MtgType.php` with `$fillable = ['category','name']`.

---

## Part 2 — BulkSyncService changes

File: `api/app/Services/BulkSyncService.php`

### 2a. Type catalog sync (new step)

Add method `syncTypeCatalog(): void` that runs at the start of every bulk sync. Fetches Scryfall catalog endpoints:

```php
$categories = [
    'supertype'            => 'https://api.scryfall.com/catalog/supertypes',
    'card_type'            => 'https://api.scryfall.com/catalog/card-types',
    'creature_subtype'     => 'https://api.scryfall.com/catalog/creature-types',
    'planeswalker_subtype' => 'https://api.scryfall.com/catalog/planeswalker-types',
    'land_subtype'         => 'https://api.scryfall.com/catalog/land-types',
    'artifact_subtype'     => 'https://api.scryfall.com/catalog/artifact-types',
    'enchantment_subtype'  => 'https://api.scryfall.com/catalog/enchantment-types',
    'spell_subtype'        => 'https://api.scryfall.com/catalog/spell-types',
];

foreach ($categories as $cat => $url) {
    $data = $this->scryfall->get($url);
    foreach ($data['data'] as $name) {
        MtgType::upsert(
            [['category' => $cat, 'name' => $name]],
            ['category','name'],
            []
        );
    }
}
```

Load multi-word subtypes into memory once for parse-time use:
```php
$this->multiWordSubtypes = MtgType::query()
    ->whereIn('category', ['creature_subtype','planeswalker_subtype','land_subtype',
                           'artifact_subtype','enchantment_subtype','spell_subtype'])
    ->where('name', 'LIKE', '% %')
    ->pluck('name')
    ->all();
```

### 2b. Type-line parsing

New helper `parseTypeLine(string $typeLine, array $multiWordSubtypes): array`:

```php
private function parseTypeLine(string $typeLine, array $multiWord): array
{
    // Split on em-dash variants
    $parts = preg_split('/\s+[—–-]\s+/u', $typeLine, 2);
    $left  = $parts[0] ?? '';
    $right = $parts[1] ?? '';

    $knownSupertypes = ['Basic','Legendary','Snow','World','Elite','Ongoing','Host','Token'];
    $leftTokens = preg_split('/\s+/', trim($left)) ?: [];

    $supertypes = [];
    $types      = [];
    foreach ($leftTokens as $tok) {
        if (in_array($tok, $knownSupertypes, true)) $supertypes[] = $tok;
        elseif ($tok !== '') $types[] = $tok;
    }

    // Subtype parsing: greedy multi-word match first
    $subtypes = [];
    $rightRemaining = trim($right);
    foreach ($multiWord as $mw) {
        if (str_contains($rightRemaining, $mw)) {
            $rightRemaining = trim(str_replace($mw, '', $rightRemaining));
            $subtypes[] = $mw;
        }
    }
    $remainingTokens = preg_split('/\s+/', $rightRemaining) ?: [];
    foreach ($remainingTokens as $tok) {
        if ($tok !== '') $subtypes[] = $tok;
    }

    return compact('supertypes','types','subtypes');
}
```

### 2c. `applyBulkCardData()` additions

At top of the card loop, add the paper-only filter:
```php
if (!in_array('paper', $card['games'] ?? [], true)) {
    continue;
}
```

Add to the upsert payload (in the existing mapping at lines 316-340):
```php
$parsed = $this->parseTypeLine($card['type_line'] ?? '', $this->multiWordSubtypes);

$row = [
    // ... existing fields ...
    'supertypes'   => json_encode($parsed['supertypes']),
    'types'        => json_encode($parsed['types']),
    'subtypes'     => json_encode($parsed['subtypes']),
    'released_at'  => $card['released_at'] ?? null,
    'promo'        => (bool)($card['promo'] ?? false),
    'variation'    => (bool)($card['variation'] ?? false),
    'set_type'     => $card['set_type'] ?? null,
    'oversized'    => (bool)($card['oversized'] ?? false),
    'is_default_eligible' =>
        !($card['promo'] ?? false)
        && !($card['variation'] ?? false)
        && !($card['oversized'] ?? false)
        && !in_array($card['set_type'] ?? null,
            ['memorabilia','funny','token','minigame'], true),
    'printed_text'      => $card['printed_text']
                           ?? ($card['card_faces'][0]['printed_text'] ?? null),
    'printed_text_back' => $card['card_faces'][1]['printed_text'] ?? null,
];
```

### 2d. Post-sync orphan cleanup

At the end of the bulk sync command, after tag sync:
```php
DB::delete('DELETE FROM card_oracle_tags WHERE oracle_id NOT IN (SELECT oracle_id FROM scryfall_cards)');
```

---
## Part 3 — `CardSearchService`

New file: `api/app/Services/CardSearchService.php`

### Public method

```php
public function search(string $query, array $options = []): array
// $options: deck_id, owned_only, per_page, page, user_id
// Returns: ['builder' => Builder, 'warnings' => string[]]
```

### Architecture

**Tokenizer.** Splits raw query into a tree of AND/OR/NOT nodes with leaf nodes `{op, comparator, value, negated}`. Handles:
- Bare text → name LIKE token
- `op:value` / `op>=value` / `op<value` / `op!=value` / `op=value` etc.
- `!"exact value"` — exact match marker
- `"quoted value"` — single literal token (spaces preserved)
- `-op:val` / `NOT op:val` — leaf negation (flips the leaf's `negated` flag)
- `-(...)` / `NOT (...)` — group negation (wraps the subtree's generated SQL in `NOT (...)`). Implemented by propagating `negated` on the group node, applied at builder time via `$q->whereNot(fn($sub) => …)`.
- `(...)` parenthesized subgroups
- `OR` keyword at group level (AND is default). Precedence: `NOT` > `AND` > `OR`.

**Query builder.** Walks the tree and applies each constraint to an Eloquent builder. Unsupported operators: push into `$warnings`, skip the constraint.

### Supported operators

| Operator | Column / Technique |
|---|---|
| bare, `name:`, `n:` | `name LIKE ?` with `%value%` (column collation is ci; no `LOWER()` wrap — that would block sargability) |
| `!"text"` | `name = ?` (collation-insensitive equality) |
| `"text"` | literal LIKE with spaces preserved |
| `o:`, `oracle:` | `oracle_text LIKE ? OR oracle_text_back LIKE ?` (no `LOWER()`) |
| `fo:`, `fulloracle:` | `printed_text LIKE ? OR printed_text_back LIKE ? OR oracle_text LIKE ? OR oracle_text_back LIKE ?` (includes reminder text) |
| `t:`, `type:` | `JSON_OVERLAPS(supertypes, JSON_ARRAY(title_case_v)) OR JSON_OVERLAPS(types, ...) OR JSON_OVERLAPS(subtypes, ...)` |
| `c:`, `color:` | colors JSON; superset default. `c:c` → `JSON_LENGTH=0`. `c:m` → `JSON_LENGTH>=2`. `c=wg`, `c<=wg`, `c>=wg` supported. |
| `ci:`, `id:`, `identity:`, `color_identity:` | same logic as `c:` but against `color_identity`. Superset default. Comparators `=`, `<=`, `>=`, `!=` supported. `ci:c` / `ci:m` shortcuts apply. |
| `commander:` | **subset only, no comparators.** `JSON_LENGTH(color_identity) = 0 OR JSON_CONTAINS(:target_array, color_identity)`. |
| `m:`, `mana:` | `mana_cost LIKE` with pip-order-tolerant normalization |
| `cmc:`, `mv:`, `manavalue:` | `cmc` column. Normalize `cmc:2` → `2.00`. All comparators. |
| `pow:`, `power:` | `CAST(power AS SIGNED)`; `*`/`X` → 0 |
| `tou:`, `toughness:` | same as power |
| `loy:`, `loyalty:` | same as power (on `loyalty`) |
| `r:`, `rarity:` | `FIELD(rarity, 'common','uncommon','rare','mythic')` for comparisons |
| `s:`, `e:`, `set:`, `edition:` | `LOWER(set_code) = LOWER(v)` |
| `cn:`, `number:` | `collector_number = v` (string) |
| `f:`, `format:`, `legal:` | `JSON_EXTRACT(legalities, '$.{format}') = 'legal'`; unknown format → warning + drop |
| `banned:` | same path, value `'banned'` |
| `restricted:` | same path, value `'restricted'` |
| `kw:`, `keyword:` | `JSON_OVERLAPS(keywords, JSON_ARRAY(title_case_v))` |
| `otag:`, `function:`, `oracletag:` | `EXISTS (SELECT 1 FROM card_oracle_tags t WHERE t.oracle_id = scryfall_cards.oracle_id AND t.tag = v)` |
| `is:commander` | `(types∋Creature AND supertypes∋Legendary) OR oracle_text LIKE '%can be your commander%' OR partner_scope IS NOT NULL` |
| `is:oathbreaker` | `types∋Planeswalker AND supertypes∋Legendary` |
| `is:partner` | `partner_scope IS NOT NULL` |
| `is:gc` | `commander_game_changer = true` |
| `is:dfc` | `is_dfc = true` |
| `is:transform` | `layout = 'transform'` |
| `is:mdfc` | `layout = 'modal_dfc'` |
| `is:flip` | `layout = 'flip'` |
| `is:meld` | `layout = 'meld'` |
| `is:split` | `layout = 'split'` |
| `is:leveler` | `layout = 'leveler'` |
| `is:reserved` | `reserved = true` |
| `is:brawler` | `(types∋Creature OR types∋Planeswalker) AND supertypes∋Legendary` |
| `is:companion` | `JSON_OVERLAPS(keywords, JSON_ARRAY('Companion'))` |
| `order:` + `direction:` | consumed by `applySort()`, not applied to WHERE |

Negation `-op:val` / `NOT op:val` wraps the generated constraint in `NOT (...)`.

### Unsupported → warnings

Non-exhaustive: `a:`, `art:`, `atag:`, `arttag:`, `flavor:`, `ft:`, `watermark:`, `border:`, `frame:`, `game:`, `in:`, `year:`, `date:`, `prints=`, `sets=`, `usd:`, `eur:`, `tix:`, `prefer:`, `unique:`, `display:`, `devotion:`, `produces:`, `is:reprint`, `is:booster`, `/regex/`, `order:color`.

### `applySort(Builder $builder, string $column, string $direction): void`

Centralized sort logic:
- `name` → `name <dir>`
- `cmc`/`mv` → `cmc <dir>`
- `rarity` → `FIELD(rarity, 'common','uncommon','rare','mythic') <dir>`
- `power`/`toughness` → `CAST(<col> AS SIGNED) <dir>, <col> IS NULL ASC`
- `set` → representative's `set_code <dir>`
- `released` → `oracle_max_released <dir>` (from the window query in Part 4)
- `edhrec` → `edhrec_rank IS NULL ASC, edhrec_rank <dir>`
- default → `name ASC`

---

## Part 4 — Search API endpoints

### `GET /api/scryfall-cards/search`

Query params:
- `q` (string) — raw Scryfall syntax
- `per_page` (int, default 60, max 100)
- `page` (int)
- `deck_id` (int, optional) — scopes to cards legal in deck's format + identity
- `owned_only` (bool) — filters to oracles where user owns ≥1 copy
- `apply_format` (bool, default true) — only meaningful with `deck_id`; pill toggle off disables the format constraint
- `apply_identity` (bool, default true) — only meaningful with `deck_id`; pill toggle off disables the color-identity constraint

Allowed format whitelist (mirrors `decks.format` enum): `commander`, `brawler`, `standard`, `pioneer`, `modern`, `legacy`, `vintage`, `pauper`. Any `f:`/`banned:`/`restricted:` value outside this list emits a warning and drops the constraint; the controller never interpolates an un-whitelisted format into `whereRaw`.

Flow in `api/app/Http/Controllers/ScryfallCardController::search()`:

1. Parse via `CardSearchService::search($q, [...])` → `{builder, warnings}`.
2. If `deck_id`: load via `Deck::where('user_id', auth()->id())->findOrFail($deckId)` (404 on miss). Extract `format` + `color_identity`; append SQL constraints gated by `apply_format` / `apply_identity`:
   ```php
   if ($applyFormat && in_array($deck->format, self::FORMAT_WHITELIST, true)) {
       $builder->whereRaw("JSON_EXTRACT(legalities, '$.".$deck->format."') = 'legal'");
   }
   if ($applyIdentity) {
       $deckIdentityArr = str_split(strtoupper($deck->color_identity ?? ''));
       $builder->whereRaw(
           "(JSON_LENGTH(color_identity) = 0 OR JSON_CONTAINS(?, color_identity))",
           [json_encode($deckIdentityArr)]
       );
   }
   ```
3. `owned_only` is deferred to the outer query (Step 4) so the representative picker still considers every printing.
4. Wrap in the oracle-grouping window query. `ownership_map` is a scalar subquery so we can later swap to a join without reworking the SQL shape. The inner `<CardSearchService filters>` is realised by rendering the builder's WHERE clause via `toSql()` + bindings merging:
   ```sql
   SELECT * FROM (
     SELECT sc.*, ow.qty_owned,
       MAX(sc.released_at) OVER (PARTITION BY sc.oracle_id) AS oracle_max_released,
       COUNT(*)           OVER (PARTITION BY sc.oracle_id) AS printing_count,
       ROW_NUMBER() OVER (
         PARTITION BY sc.oracle_id
         ORDER BY
           CASE WHEN ow.qty_owned > 0 THEN 0 ELSE 1 END,
           CASE WHEN ow.qty_owned > 0 THEN NULL
                WHEN sc.is_default_eligible THEN 0 ELSE 1 END,
           sc.released_at DESC,
           sc.set_code ASC
       ) AS rn
     FROM scryfall_cards sc
     LEFT JOIN (
       SELECT ce.scryfall_id, SUM(ce.quantity) AS qty_owned
       FROM collection_entries ce
       WHERE ce.user_id = :uid
       GROUP BY ce.scryfall_id
     ) ow ON ow.scryfall_id = sc.scryfall_id
     WHERE <CardSearchService filters>
   ) x
   WHERE rn = 1
     {{ if owned_only: AND qty_owned > 0 }}
   ORDER BY <applySort output>
   LIMIT :per_page OFFSET :offset
   ```
5. **Pagination is manual.** Laravel's `paginate()` would clone the builder and COUNT with `rn` stripped — that counts printings, not oracles. Instead:
   ```php
   // Data query: the full window SQL above, with LIMIT/OFFSET bound.
   $rows = DB::select($windowSql, $bindings);

   // Total query: same inner SELECT and filters, counted at the oracle layer.
   $total = (int) DB::selectOne(
       "SELECT COUNT(*) AS n FROM (
          SELECT DISTINCT sc.oracle_id
          FROM scryfall_cards sc
          LEFT JOIN (…ow subquery…) ow ON ow.scryfall_id = sc.scryfall_id
          WHERE <CardSearchService filters>
            {{ if owned_only: AND ow.qty_owned > 0 }}
        ) t",
       $innerBindings
   )->n;

   $paginator = new LengthAwarePaginator($rows, $total, $perPage, $page, [
       'path' => request()->url(),
       'query' => request()->query(),
   ]);
   ```
6. Call `ownershipMap()` (oracle-aggregated; see Part 5) for the paged slice.
7. Return the paginator's `toArray()` shape with ownership fields merged onto `data[]` rows and `warnings` appended at the top level.

Response shape:
```json
{
  "data": [
    {
      "oracle_id": "...",
      "scryfall_id": "...",
      "name": "...",
      "set_code": "...",
      "collector_number": "...",
      "released_at": "2025-03-11",
      "rarity": "rare",
      "mana_cost": "{2}{G}{G}",
      "cmc": 4.00,
      "colors": ["G"],
      "color_identity": ["G"],
      "type_line": "Legendary Creature — Elf Druid",
      "supertypes": ["Legendary"],
      "types": ["Creature"],
      "subtypes": ["Elf","Druid"],
      "oracle_text": "...",
      "printed_text": "...",
      "power": "2",
      "toughness": "3",
      "loyalty": null,
      "layout": "normal",
      "is_dfc": false,
      "image_small": "...",
      "image_normal": "...",
      "image_large": "...",
      "image_small_back": null,
      "image_normal_back": null,
      "image_large_back": null,
      "partner_scope": null,
      "commander_game_changer": false,
      "oracle_tags": ["ramp"],
      "printing_count": 3,
      "owned_count": 2,
      "available_count": 1,
      "wanted_by_others": 3
    }
  ],
  "current_page": 1,
  "last_page": 6,
  "per_page": 60,
  "total": 347,
  "warnings": ["Operator 'art' is not supported"]
}
```

### `GET /api/scryfall-cards/printings?oracle_id=<uuid>`

Returns all printings of an oracle with per-printing + foil-split ownership. Sorted by `released_at DESC`. Auth required.

```json
{
  "data": [
    {
      "scryfall_id": "...",
      "set_code": "tdm",
      "set_name": "Tarkir: Dragonstorm",
      "icon_svg_uri": "https://svgs.scryfall.io/sets/tdm.svg",
      "collector_number": "234",
      "released_at": "2025-03-11",
      "rarity": "mythic",
      "image_small": "...",
      "image_normal": "...",
      "image_small_back": null,
      "image_normal_back": null,
      "is_default_eligible": true,
      "promo": false,
      "variation": false,
      "ownership": {
        "nonfoil": 2,
        "foil": 1,
        "available_nonfoil": 1,
        "available_foil": 1
      }
    }
  ]
}
```

Queries:
- Primary: `SELECT sc.*, ms.name AS set_name, ms.icon_svg_uri FROM scryfall_cards sc LEFT JOIN mtg_sets ms ON ms.code = sc.set_code WHERE sc.oracle_id = ? ORDER BY sc.released_at DESC`
- Ownership: `SELECT ce.scryfall_id, ce.foil, SUM(ce.quantity) AS qty FROM collection_entries ce WHERE ce.user_id = ? AND ce.scryfall_id IN (...) GROUP BY ce.scryfall_id, ce.foil`
- Commitments per printing: `SELECT ce.scryfall_id, ce.foil, COUNT(*) AS used FROM deck_entries de JOIN collection_entries ce ON ce.id = de.physical_copy_id WHERE ce.user_id = ? AND ce.scryfall_id IN (...) GROUP BY ce.scryfall_id, ce.foil`

Routes file (`api/routes/api.php`):
```php
Route::get('scryfall-cards/search',    [ScryfallCardController::class, 'search']);
Route::get('scryfall-cards/printings', [ScryfallCardController::class, 'printings']);
```

---

## Part 5 — Ownership aggregation

Replace the per-scryfall `ownershipMap()` in `ScryfallCardController` with oracle-aggregated version:

```php
protected function ownershipMap(array $oracleIds, ?int $excludeDeckId = null): array
// Returns: [oracle_id => ['owned' => int, 'available' => int, 'wanted_by_others' => int]]
```

Three queries, all keyed on `auth()->id()`:

```sql
-- owned (aggregate across all printings of the oracle)
SELECT sc.oracle_id, SUM(ce.quantity) AS qty
FROM collection_entries ce
JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id
WHERE ce.user_id = :uid AND sc.oracle_id IN (:oracleIds)
GROUP BY sc.oracle_id;

-- committed (physical copies assigned to decks)
SELECT sc.oracle_id, COUNT(*) AS used
FROM deck_entries de
JOIN collection_entries ce ON ce.id = de.physical_copy_id
JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id
WHERE ce.user_id = :uid AND sc.oracle_id IN (:oracleIds)
GROUP BY sc.oracle_id;

-- wanted by others (deck slots across decks other than current)
SELECT sc.oracle_id, SUM(de.quantity) AS wanted
FROM deck_entries de
JOIN decks d ON d.id = de.deck_id
JOIN scryfall_cards sc ON sc.scryfall_id = de.scryfall_id
WHERE d.user_id = :uid
  AND sc.oracle_id IN (:oracleIds)
  AND (:excludeDeckId IS NULL OR d.id != :excludeDeckId)
GROUP BY sc.oracle_id;
```

`available = owned - committed`. `wanted_by_others` from the third query.

---
## Part 6 — Cleanup artisan command

New artisan command: `scryfall:purge-non-paper`
File: `api/app/Console/Commands/PurgeNonPaperCards.php`

Steps:

1. **Dry-run preflight.** Read `scryfall_card_raws` and identify scryfall_ids where `JSON_SEARCH(data, 'one', 'paper', NULL, '$.games')` is NULL (i.e., the card's `games` array does not contain `paper`).
2. Query `collection_entries` and `deck_entries` for any rows referencing those scryfall_ids:
   ```sql
   SELECT 'collection' AS src, ce.id, ce.user_id, ce.scryfall_id, sc.name
   FROM collection_entries ce
   JOIN scryfall_cards sc ON sc.scryfall_id = ce.scryfall_id
   WHERE ce.scryfall_id IN (:nonPaperIds)
   UNION ALL
   SELECT 'deck' AS src, de.id, d.user_id, de.scryfall_id, sc.name
   FROM deck_entries de
   JOIN decks d ON d.id = de.deck_id
   JOIN scryfall_cards sc ON sc.scryfall_id = de.scryfall_id
   WHERE de.scryfall_id IN (:nonPaperIds);
   ```
   If count > 0: print a human-readable report grouped by user + card, exit non-zero. **Do not modify anything.**
3. If count == 0: run truncate sequence:
   ```sql
   SET FOREIGN_KEY_CHECKS=0;
   TRUNCATE scryfall_cards;
   TRUNCATE scryfall_card_raws;
   SET FOREIGN_KEY_CHECKS=1;
   ```
4. Invoke the existing bulk-sync command (`scryfall:sync-bulk`) — the paper-only filter + new mapping from Part 2 populates only paper cards with the new fields.
5. Invoke the oracle-tag sync (existing command).
6. Orphan cleanup:
   ```sql
   DELETE FROM card_oracle_tags WHERE oracle_id NOT IN (SELECT oracle_id FROM scryfall_cards);
   ```
7. Report: row counts in `scryfall_cards` before/after.

Runbook must reference this command as a one-shot operation to run immediately after the migration deploys.

---
## Part 7 — Frontend: store + routing

### New store `web/src/stores/catalog.js`

```js
state: {
  query: '',
  results: [],                    // oracle-grouped card rows
  total: 0,
  page: 1,
  lastPage: 1,
  loading: false,
  loadingMore: false,
  warnings: [],
  warningsDismissed: false,
  ownedOnly: false,
  displayMode: 'grid',            // 'grid' | 'strip'
  cardSize: 'medium',             // 'small' | 'medium' | 'large'
  activeCardOracleId: null,
  activePrintings: {},            // oracle_id -> scryfall_id override
  printingsByOracle: {},          // oracle_id -> [printings[]] (lazy-loaded)
  printingsLoading: {},           // oracle_id -> bool
  deckFilters: {
    format: null,
    colorIdentity: null,
    formatActive: true,           // togglable pill
    colorIdentityActive: true,
  },
}

actions:
  search(q, { deckId, ownedOnly })    // GET /api/scryfall-cards/search
                                       // sends apply_format/apply_identity
                                       // from deckFilters.{formatActive,colorIdentityActive}
  loadMore()                          // appends next page (same params)
  setActiveCard(oracleId)             // opens sidebar, triggers printings fetch
  fetchPrintings(oracleId)            // GET /api/scryfall-cards/printings
  pickPrinting(oracleId, scryfallId)  // catalog-local override
  clearActive()
  toggleDeckFilter(which)             // 'format' | 'colorIdentity'
  setDisplayMode(m)                   // persist to localStorage
  setCardSize(s)                      // persist to localStorage
  dismissWarnings()
```

`displayMode` and `cardSize` persist to `localStorage` (same pattern as `settings.js`).

### Router

Add temporary route in `web/src/router/index.js`:
```js
{ path: '/catalog', component: () => import('@/views/CatalogView.vue') }
// TODO(DB-3): remove standalone /catalog route once tab system lands
```

---

## Part 8 — Frontend: CatalogPanel + CardTile

### `web/src/views/CatalogView.vue` (new)

Thin wrapper: top-level shell that mounts `CatalogPanel` (no `deckId` prop) alongside `CatalogDetailSidebar`. Layout mirrors `CollectionView`'s flex-row shell.

### `web/src/components/CatalogPanel.vue` (new)

Props:
- `deckId: { type: Number, default: null }`

Layout:
```
┌─────────────────────────────────────────────────────┐
│ [SyntaxSearch input]   "3,247 cards"                │
│ [Color ▾] [Type ▾] [Format ▾] [CMC ▾] [Rarity ▾]   │
│ [All Cards | Owned Only]   [Grid⋮Strip]  [S M L]   │
│ (if deckId: [✓ Commander] [✓ ⊆ WUBG] toggleable)   │
├─────────────────────────────────────────────────────┤
│ ⚠ 2 ignored operators: art, usd          [×]       │
├─────────────────────────────────────────────────────┤
│ [virtualized grid of CardTile]                      │
└─────────────────────────────────────────────────────┘
```

Implementation notes:
- **Search input** reuses `SyntaxSearch.vue` unchanged (input-only, `v-model:modelValue`).
- **Filter chips were cut.** Early prototype had `+ color` / `+ type` / `+ rarity` token-appender buttons under the search input; removed on user feedback as clutter that duplicated what typing the syntax directly already does. The syntax search's inline `?` help button covers the discoverability gap.
- **Ownership toggle** sets `catalog.ownedOnly`; re-fires search.
- **Display mode toggle** (Grid / Strip) sets `catalog.displayMode`. Grid renders `CardTile.vue`; Strip renders `CatalogStrip.vue` in a column-packed layout. Size picker (S/M/L) only applies in Grid mode; Strip mode follows the global `--card-width` / `--strip-height` settings.
- **Card size** radio (S/M/L) sets `catalog.cardSize` — grid-only.
- **Deck-filter pills** (when `deckId` set): render one pill per active implicit filter. Click toggles its `active` state in `catalog.deckFilters`. When toggled off, the matching SQL constraint is dropped on next search.
- **Warnings banner** (dismissible): visible when `catalog.warnings.length && !catalog.warningsDismissed`.
- **Debounce** search at 300ms on query change. Enter triggers immediate search.
- **Result count** (`3,247 cards found`) displays next to search input, bound to `catalog.total`.
- **Virtual grid** via `@tanstack/vue-virtual`. Layout: CSS Grid with `grid-template-columns: repeat(auto-fill, minmax(<size>px, 1fr))`. Row virtualization via `useVirtualizer` with row heights computed from card aspect ratio (Magic card ~= 1.4:1 height:width). Scroll-bottom detection triggers `catalog.loadMore()`.

### `web/src/components/CardTile.vue` (new)

Props:
- `card: Object` (search result row, oracle-grouped)
- `size: 'small' | 'medium' | 'large'`
- `deckId: Number | null`

Emits:
- `click` (opens sidebar)
- `add-to-deck({ scryfall_id, zone })` (only when `deckId !== null`)

Template:
```vue
<div class="card-tile"
     :class="[sizeClass, shineClass]"
     :draggable="true"
     @click="onClick"
     @dragstart="onDragStart"
     @mouseenter="onHoverEnter"
     @mouseleave="onHoverLeave">
  <img :src="imageSrc"
       :srcset="imageSrcset"
       :sizes="imageSizes"
       :alt="card.name"/>

  <span v-if="card.owned_count > 0" class="badge badge-owned">
    {{ card.owned_count }}
  </span>
  <span v-if="card.wanted_by_others > 0" class="badge badge-wanted">
    {{ card.wanted_by_others }}
  </span>

  <DfcPopover v-if="card.is_dfc && hovered"
              :back-image="card.image_normal_back"
              :anchor="rootRef"/>

  <div v-if="deckId && addMenuOpen" class="add-menu" @click.stop>
    <button @click="emitAdd('main')">+ Main</button>
    <button @click="emitAdd('side')">+ Side</button>
    <button @click="emitAdd('maybe')">+ Maybe</button>
  </div>
</div>
```

Shine class logic:
```js
const shineClass = computed(() => {
  const { owned_count: owned, available_count: avail } = props.card
  if (owned > 0 && avail > 0) return 'shine-green'
  if (owned > 0 && avail === 0) return 'shine-blue'
  return 'shine-red'
})
```

Image selection (size → src):
- `small` tile → `src = image_small`, srcset includes `image_normal` at 2x
- `medium` tile → `src = image_normal`
- `large` tile → `src = image_large` (fallback `image_normal` if null)

`srcset` example:
```js
const imageSrcset = computed(() => [
  props.card.image_small   ? `${props.card.image_small} 146w`   : null,
  props.card.image_normal  ? `${props.card.image_normal} 488w`  : null,
  props.card.image_large   ? `${props.card.image_large} 672w`   : null,
].filter(Boolean).join(', '))
```

Click handler:
```js
function onClick(e) {
  if (props.deckId !== null) {
    addMenuOpen.value = true
    return
  }
  catalog.setActiveCard(props.card.oracle_id)
  emit('click')
}
```

Drag payload:
```js
function onDragStart(e) {
  e.dataTransfer.setData('application/json', JSON.stringify({
    oracle_id: props.card.oracle_id,
    scryfall_id: effectiveScryfallId.value,
    source: 'catalog',
  }))
  e.dataTransfer.effectAllowed = 'copy'
}
```

Hover (DFC popover): 300ms delay before showing, immediate hide on mouseleave.

---
## Part 9 — Frontend: Detail sidebar refactor

### Extract `web/src/components/CardDetailBody.vue` (new)

Pull the card-display markup out of the existing `DetailSidebar.vue` (currently lines 105-139, 196-209):
- Card image with DFC flip button (existing logic at lines 105-114)
- Name
- Set code + collector number + rarity + mana cost metadata row
- Type line
- Oracle text with mana symbol parsing
- Power/toughness or loyalty
- Legalities grid (7 formats)

**Props:** `card: Object` (raw ScryfallCard shape, or the `.card` field of a collection entry).

No store coupling. No actions. Pure display. DFC flip state stays local (`ref(false)`).

### Refactor `web/src/components/DetailSidebar.vue`

Replace the card-display markup with `<CardDetailBody :card="card" />`. Keep:
- Header + close button (unchanged)
- "Your Copies" section (condition, location, quantity, foil editors)
- "Wanted by decks" warning block
- All existing collection-store coupling (patching, activeEntry)

Net diff: ~40 lines removed, 2 lines added (import + usage), rest unchanged.

### New `web/src/components/CatalogDetailSidebar.vue`

```vue
<template>
  <aside v-if="activeCard" class="vk-detail vk-detail--catalog">
    <header>
      <button class="close" @click="catalog.clearActive()">×</button>
    </header>

    <CardDetailBody :card="representativePrinting"/>

    <section class="printings">
      <h3>Printings ({{ printings.length }})</h3>
      <ul v-if="!loading">
        <li v-for="p in printings"
            :key="p.scryfall_id"
            :class="{ selected: p.scryfall_id === selectedPrintingId }"
            @click="catalog.pickPrinting(activeCard.oracle_id, p.scryfall_id)">
          <input type="radio"
                 :checked="p.scryfall_id === selectedPrintingId"/>
          <img v-if="p.icon_svg_uri" class="set-icon" :src="p.icon_svg_uri" :alt="p.set_code"/>
          <div class="printing-meta">
            <span class="set-name">{{ p.set_name }}</span>
            <span class="set-code-num">
              {{ p.set_code.toUpperCase() }} · #{{ p.collector_number }}
            </span>
            <span class="release">{{ formatDate(p.released_at) }}</span>
          </div>
          <div class="ownership">
            <span v-if="p.ownership.nonfoil">
              {{ p.ownership.nonfoil }}× nonfoil
              ({{ p.ownership.available_nonfoil }} free)
            </span>
            <span v-if="p.ownership.foil">
              {{ p.ownership.foil }}× foil
              ({{ p.ownership.available_foil }} free)
            </span>
          </div>
        </li>
      </ul>
      <div v-else class="loading">Loading printings…</div>
    </section>
  </aside>
</template>
```

Computed wiring:
```js
const activeCard = computed(() => {
  const oid = catalog.activeCardOracleId
  return oid ? catalog.results.find(r => r.oracle_id === oid) : null
})

const printings = computed(() =>
  catalog.printingsByOracle[catalog.activeCardOracleId] || []
)

const loading = computed(() =>
  !!catalog.printingsLoading[catalog.activeCardOracleId]
)

const selectedPrintingId = computed(() =>
  catalog.activePrintings[catalog.activeCardOracleId]
    || activeCard.value?.scryfall_id
)

const representativePrinting = computed(() => {
  const p = printings.value.find(p => p.scryfall_id === selectedPrintingId.value)
  return p || activeCard.value
})

// On activeCardOracleId change, fetch printings if not cached
watch(() => catalog.activeCardOracleId, (oid) => {
  if (oid && !catalog.printingsByOracle[oid]) {
    catalog.fetchPrintings(oid)
  }
})
```

Mounted in `CatalogView.vue` alongside `CatalogPanel`. Layout mirrors the collection view's sidebar-right flex (fixed width via `--detail-width` CSS var).

---

## Part 10 — Frontend: DfcPopover primitive + drag-and-drop

### `web/src/components/DfcPopover.vue` (new)

Props:
- `backImage: String` (image URL for back face)
- `anchor: HTMLElement | null` (positioning anchor — typically the tile root)

```vue
<template>
  <Teleport to="body">
    <div ref="popoverEl"
         class="dfc-popover"
         :style="positionStyle">
      <img :src="backImage" alt="Back face"/>
    </div>
  </Teleport>
</template>
```

Positioning: `position: fixed`. Compute `top`/`left` from `anchor.getBoundingClientRect()`. Clamp to viewport edges. Recompute on scroll/resize via window listeners (cleaned up on unmount).

Wired into `CardTile.vue` (DB-2 consumer only). Collection-side wire-up is a follow-up issue (see Out of scope).

### Drag-and-drop (tile side)

In `CardTile.vue`, the `onDragStart` handler (see Part 8):
```js
e.dataTransfer.setData('application/json', JSON.stringify({
  oracle_id: props.card.oracle_id,
  scryfall_id: effectiveScryfallId.value,
  source: 'catalog',
}))
e.dataTransfer.effectAllowed = 'copy'
```

No drop target in DB-2. DB-3's deck view will read the payload from `dragover` + `drop` and invoke the add-to-deck API.

---
## Tests

Backend only in DB-2. Frontend tests deferred (no Vitest setup yet).

### `api/tests/Unit/CardSearchServiceTest.php` (new)

DataProvider-driven. Each case: `[queryString, expectedWhereFragments, expectedWarnings]`.

Seed cases:
- Bare text → `name LIKE`
- `!"Lightning Bolt"` → exact equality
- `"Lightning Bolt"` → LIKE with literal space
- `t:legendary creature` → supertypes overlap `Legendary` + name LIKE `creature`
- `t:"artifact creature"` → LIKE on `type_line` with literal phrase
- `t:"time lord"` → subtypes overlap `Time Lord` (multi-word from mtg_type_catalog)
- Color family each distinct: `c:wg`, `c=wg`, `c<=wg`, `c>=wg`, `c:c`, `c:m`
- `ci:wug`, `ci>=wu`, `ci<=wg` — comparators work
- `id:wug` — alias behaves identically to `ci:`
- `commander:wu` — subset-only, colorless cards pass
- `commander:` does NOT support comparators (parser rejects `commander>=wu`, adds warning)
- `cmc:2`, `cmc>=4`, `cmc!=3`
- `pow>=*` — star/X handled as 0
- `r>common` — FIELD emission for rarity comparison
- `f:commander`, `banned:modern`, `restricted:vintage`
- `f:foobar` → warning + constraint dropped
- `is:commander` — all three clauses
- `is:gc`, `is:mdfc`, `is:dfc`, `is:oathbreaker`
- `otag:ramp` — EXISTS subquery
- `o:graveyard` — checks `oracle_text` + `oracle_text_back`
- `fo:flying` — checks `printed_text` + `printed_text_back` + oracle_text fallback
- `(r:rare OR r:mythic) t:creature c:w` — OR grouping
- `-t:equipment`, `NOT t:land` — negation
- Unsupported ops → warnings: `art:x`, `usd:5`, `is:reprint`, `/regex/`
- Default sort fallback when no `order:`
- `order:rarity direction:desc` → FIELD-based ORDER BY emitted
- `order:released` → uses `oracle_max_released`
- Case normalization: `t:elf` hits JSON array `"Elf"`

### `api/tests/Feature/ScryfallCardControllerSearchTest.php` (new)

Seeded fixtures: Sol Ring (3 printings), Llanowar Elves, a Doctor Who creature with `Time Lord` subtype, Teferi planeswalker, an Arena-only card (verified absent after migration).

Cases:
- `GET /api/scryfall-cards/search?q=t:creature` → one row per `oracle_id` (oracle grouping).
- `GET /api/scryfall-cards/search?q=t:"time lord"` → returns Doctor Who creature (multi-word subtype).
- `GET /api/scryfall-cards/search?q=sol+ring&deck_id=<WU-deck>` → Sol Ring returned (colorless fits any identity via `commander` subset logic).
- `GET /api/scryfall-cards/search?q=lightning+bolt&deck_id=<WU-deck>` → empty (Bolt is R, not in WU).
- `deck_id` pointing at another user's deck → 404.
- `owned_only=true` → scoped to user's collection.
- Representative picker: user owns set A printing, newer set B exists → response returns set A's scryfall_id/image/set_code.
- Representative picker: user owns no printings → returns `is_default_eligible=true` printing (not promo, not variation, not funny set_type).
- `warnings` present at top level when unsupported operator used.
- Pagination: `per_page=60`, `page=2` walks correctly without duplicates.
- Oracle-aggregated ownership: user owns 2× set A + 1× set B → `owned_count=3`.
- `wanted_by_others`: deck_entries in deck X (2 copies) and deck Y (1 copy) for the same oracle; when `deck_id=X`, response returns `wanted_by_others=1` (only Y).

### `api/tests/Feature/ScryfallCardPrintingsEndpointTest.php` (new)

- `GET /api/scryfall-cards/printings?oracle_id=<sol-ring>` → all printings sorted by `released_at DESC`.
- Ownership split by foil: seed 2 nonfoil + 1 foil of the same scryfall_id → `ownership.nonfoil=2, ownership.foil=1`.
- `available_nonfoil` / `available_foil` subtract deck commitments correctly.
- Auth required (401 without token).

### `api/tests/Unit/BulkSyncServiceTest.php` (additions)

- `parseTypeLine('Legendary Creature — Elf Warrior')` → `{supertypes:['Legendary'], types:['Creature'], subtypes:['Elf','Warrior']}`
- `parseTypeLine('Instant')` → `{supertypes:[], types:['Instant'], subtypes:[]}`
- `parseTypeLine('Basic Snow Land — Forest')` → `{supertypes:['Basic','Snow'], types:['Land'], subtypes:['Forest']}`
- `parseTypeLine('Legendary Creature — Time Lord')` with `Time Lord` seeded in `mtg_type_catalog` → `{subtypes:['Time Lord']}` (single element, not `['Time','Lord']`).
- Card with `games: ['arena']` is skipped by the paper-only filter.
- `is_default_eligible`:
  - `promo=true` → false
  - `variation=true` → false
  - `oversized=true` → false
  - `set_type=memorabilia` → false
  - `set_type=funny` → false
  - All clean → true
- `syncTypeCatalog()`: mocks Scryfall endpoints, verifies upsert into `mtg_type_catalog`.

### `api/tests/Feature/PurgeNonPaperCardsCommandTest.php` (new)

- Seed a non-paper card + a `collection_entry` referencing it → command exits non-zero with report, no data modified.
- Seed a non-paper card in `scryfall_card_raws` with no references → command runs clean, card removed after re-sync, orphan `card_oracle_tags` deleted.

---

## Runbook / verification

Order of operations after the PR merges:

1. **Deploy migrations** (two files: catalog columns + mtg_type_catalog):
   ```
   docker compose exec api php artisan migrate
   ```

2. **Run the purge + re-sync command**:
   ```
   docker compose exec api php artisan scryfall:purge-non-paper
   ```
   Expected runtime ~3-5 minutes. Exits 0 on success. Exits non-zero if any collection/deck entries reference non-paper cards (printed report; resolve manually, then re-run).

3. **Verify schema populated**:
   ```
   docker compose exec api php artisan tinker --execute="
   \$c = App\Models\ScryfallCard::where('name','Llanowar Elves')->first();
   echo json_encode(\$c->supertypes) . PHP_EOL;
   echo json_encode(\$c->types) . PHP_EOL;
   echo json_encode(\$c->subtypes) . PHP_EOL;
   echo (\$c->is_default_eligible ? 'eligible' : 'not') . PHP_EOL;
   echo \$c->released_at . PHP_EOL;
   "
   ```

4. **Verify paper-only** (Arena-only set should be 0):
   ```
   docker compose exec api php artisan tinker --execute="
   echo App\Models\ScryfallCard::where('set_code','y22')->count();
   "
   ```

5. **Verify type catalog populated**:
   ```
   docker compose exec api php artisan tinker --execute="
   echo App\Models\MtgType::where('name','Time Lord')->count();  // 1
   echo App\Models\MtgType::where('name','LIKE','% %')->count();  // multi-word count
   "
   ```

6. **API smoke tests**:
   ```bash
   TOKEN=<your_token>
   BASE="http://localhost:8080/api"

   curl -s "$BASE/scryfall-cards/search?q=t:creature%20c:g%20cmc<=2" \
     -H "Authorization: Bearer $TOKEN" | jq '.total, .data[0].name'

   curl -s "$BASE/scryfall-cards/search?q=is:commander%20ci:wub" \
     -H "Authorization: Bearer $TOKEN" | jq '.data[0] | {name, oracle_id, supertypes}'

   curl -s "$BASE/scryfall-cards/search?q=commander:wu" \
     -H "Authorization: Bearer $TOKEN" | jq '.total'

   curl -s "$BASE/scryfall-cards/search?q=t:\"time%20lord\"" \
     -H "Authorization: Bearer $TOKEN" | jq '.data[0] | {name, subtypes}'

   curl -s "$BASE/scryfall-cards/search?q=f:commander%20otag:ramp" \
     -H "Authorization: Bearer $TOKEN" | jq '.total'

   curl -s "$BASE/scryfall-cards/search?q=t:artifact%20art:x" \
     -H "Authorization: Bearer $TOKEN" | jq '.warnings'

   curl -s "$BASE/scryfall-cards/search?q=sol+ring&deck_id=1" \
     -H "Authorization: Bearer $TOKEN" | jq '.data[0].name'

   curl -s "$BASE/scryfall-cards/printings?oracle_id=<oracle-uuid>" \
     -H "Authorization: Bearer $TOKEN" | jq '.data | length, .data[0].ownership'
   ```

7. **Frontend smoke** (visit `/catalog`):
   - Grid renders with virtualized infinite scroll.
   - Result count displays next to search input.
   - Ownership shine applies (green/blue/red) per card.
   - Gold badge (top-left) = `owned_count`; red badge (top-right) = `wanted_by_others`.
   - Hovering a DFC card shows back-face popover.
   - Click a tile → `CatalogDetailSidebar` opens with Printings section and per-printing ownership.
   - Selecting a different printing updates the tile image.
   - Type `t:creature art:terese` → warnings banner appears above results.
   - Toggle "Owned Only" → results filter.
   - Resize cards via S/M/L.
   - Drag a tile → browser drag preview shows card image (no receiver in DB-2).

8. **Regression check** — collection view unchanged: top-bar syntax search still filters client-side, `DetailSidebar` still edits condition/location/quantity/foil, wanted-by-decks warning still appears.

---

## Critical files to modify / create

**Backend:**
- `api/app/Services/CardSearchService.php` — **NEW**
- `api/app/Services/BulkSyncService.php` — paper-only filter, type parsing, type catalog sync, new fields mapping, orphan cleanup
- `api/app/Console/Commands/PurgeNonPaperCards.php` — **NEW**
- `api/app/Http/Controllers/ScryfallCardController.php` — rewrite `search()`, add `printings()`, replace `ownershipMap()` with oracle-aggregated version
- `api/app/Models/ScryfallCard.php` — extend `$fillable` + `$casts`
- `api/app/Models/MtgType.php` — **NEW**
- `api/routes/api.php` — add `/scryfall-cards/printings` route; drop legacy URL params from `/scryfall-cards/search` validation
- `api/database/migrations/YYYY_MM_DD_add_catalog_columns_to_scryfall_cards.php` — **NEW**
- `api/database/migrations/YYYY_MM_DD_create_mtg_type_catalog_table.php` — **NEW**

**Frontend:**
- `web/src/stores/catalog.js` — **NEW**
- `web/src/views/CatalogView.vue` — **NEW**
- `web/src/components/CatalogPanel.vue` — **NEW**
- `web/src/components/CardTile.vue` — **NEW**
- `web/src/components/CatalogDetailSidebar.vue` — **NEW**
- `web/src/components/CardDetailBody.vue` — **NEW** (extracted from DetailSidebar)
- `web/src/components/DfcPopover.vue` — **NEW**
- `web/src/components/DetailSidebar.vue` — refactor to compose `CardDetailBody`
- `web/src/router/index.js` (or equivalent) — add temporary `/catalog` route
- `web/package.json` — add `@tanstack/vue-virtual`

---
## Out of scope for DB-2

Tickets to file (copy-paste text below).

### Wire DfcPopover into collection view CardStrip

```
Title: Extend DfcPopover into collection view CardStrip

Body:
DB-2 introduces a shared DfcPopover.vue primitive and wires it into both
CardTile and CatalogStrip on the catalog side. The collection-side CardStrip
doesn't yet show the back face for DFCs — this follow-up closes that gap.

(The "split CardStrip into display + collection-entry concerns" refactor
originally scoped here was pulled into DB-2: a minimal shared primitive now
lives at `composables/useColorSkeleton.js`, and the catalog uses its own
`CatalogStrip.vue` that shares the palette but not CardStrip's Mode A/B
and hover-peek machinery.)

Scope:
- Import DfcPopover into CardStrip.vue; render when entry.card is DFC
- In hoverMode='peek', DfcPopover appears alongside the existing peek popup
- In the existing strip display, render front + back side-by-side for DFCs
- No backend changes (DFC fields already exist on ScryfallCard)

Acceptance:
- Hovering a DFC card in the collection view shows both faces
- Non-DFC cards unchanged
- Strip mode shows both faces for DFCs

Blocked by: DB-2 landing DfcPopover.vue.
```

### Track foil status on deck_entries

```
Title: Add foil column to deck_entries

Body:
collection_entries has a foil column; deck_entries does not. Deckbuilder UX
needs to distinguish foil slots from non-foil (e.g., foil Sol Ring in a
commander deck).

Scope:
- Migration: add nullable `foil` boolean to deck_entries
- Update DeckEntry model $fillable + $casts
- Endpoints that create/update deck_entries accept `foil`
- UI: surface foil toggle in deckbuilder card-slot editor

Out of scope:
- Price differentiation (no price tracking in the app)

Related: DB-3 deckbuilder frontend surfaces the UI.
```

### Catalog virtualization perf review

```
Title: Catalog virtual scrolling perf review

Body:
DB-2 ships infinite scroll with @tanstack/vue-virtual. Revisit with real
usage data: measure frame-time on 3k+ results, tune overscan, verify
srcset picks appropriate image sizes at each card-size level. Consider
content-visibility: auto as a simpler alternative if the library proves
heavy or interferes with grid layout.
```

### Catalog: color sort operator

```
Title: Add order:color to catalog search

Body:
DB-2 cut order:color from the supported sort operators. Revisit if user
feedback indicates demand. Implementation would need a deterministic pip
ordering that matches Scryfall (W, U, B, R, G, multicolor by count,
colorless, land) or an app-specific convention. The SQL is non-trivial
(CASE-based FIELD composition).
```

### Remove standalone /catalog route after DB-3

```
Title: Remove temporary /catalog route

Body:
DB-2 adds a standalone /catalog route so CatalogPanel can be tested
without the deckbuilder tab system. DB-3 will embed CatalogPanel as a
tab inside the deck-view tab system.

Scope:
- Remove /catalog route from web/src/router
- Verify CatalogView.vue has no remaining imports or tests referencing it
- Confirm CatalogPanel still mounts correctly inside DB-3's tab shell

Blocked by: DB-3 tab system landing.
```

---
