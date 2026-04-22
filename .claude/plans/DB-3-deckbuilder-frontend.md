# DB-3 — Deckbuilder Frontend

Wires the DB-1 deck API and DB-2 card catalog into a usable deckbuilder UI within the existing three-column layout. Includes a small prerequisite backend slice (companion slot, produced_mana, owned-copies endpoint) that was uncovered during planning and is rolled into DB-3.

## Decisions locked in during planning

1. **Route shape:** `/collection/decks/:id` renders the deck view. `/collection` continues to render the collection view. The deck view is a separate route — bookmarkable and reload-safe. No polymorphic `activeLocation` pretending decks and drawers are the same thing.

2. **Detail sidebar pattern:** new `DeckDetailSidebar.vue` wrapper parallel to `DetailSidebar.vue` (collection) and `CatalogDetailSidebar.vue` (catalog). All three wrap `CardDetailBody.vue` and add context-specific sections. No `context` prop on `CardDetailBody`.

3. **Tab system scope:** full terminal-style binary-tree panel layout — arbitrary nested splits, drag-to-split on any of four edges, resize handles between splits, tabs within each leaf. Layout persists globally in localStorage.

4. **Available tab types:** `deck`, `catalog`, `analysis`, `illegalities`, `side`, `maybe`. Side and Maybe can be undocked from the Deck tab into their own tab; redock button on the tab + closing the undocked tab also redocks.

5. **Illegality unignore endpoint is POST `/api/decks/{deck}/illegalities/unignore`** — not DELETE. Two parallel POST endpoints in `api/routes/api.php:57-58`.

6. **Commander data source:** commanders come from `deck.commander1` / `deck.commander2` objects on the deck response, not from a zone filter. Zone enum is only `main|side|maybe`. Commanders are synced into `deck_entries` with `is_commander=true` on the backend already (`DeckController.php:164-204`).

7. **Companion modeling:** add `companion_scryfall_id` uuid FK column on `decks` table mirroring the commander pattern. Player explicitly picks the companion; the card must also be a deck_entries row (main or side zone). Backend migration + endpoint work included in DB-3.

8. **Produced_mana modeling:** add `produced_mana` JSON column on `scryfall_cards` populated from Scryfall bulk data (Scryfall exposes this directly). Analysis tab reads it for production %. Accurate numbers beat heuristics.

9. **Owned-copies endpoint:** new `GET /api/collection/copies?scryfall_id={uuid}` returning the authed user's `collection_entries` for that card, with `location_name` joined. Used by the deck detail sidebar's physical-copy dropdown.

10. **Drag-to-remove semantics:** dragging a card out of the deck deletes the entire `deck_entries` row for that zone. Other zones' rows (same card in sideboard, maybe) are untouched.

11. **Sidebar integration:** decks render interleaved with locations inside `location_groups`. Backend already supports `decks.group_id → location_groups.id`; frontend merges decks and locations within each group sorted by `sort_order`.

12. **Partner/Background/Companion/Signature rendering:**
    - Companion: strip above commander slot 1 (card strip expands on hover, collapses commander tile).
    - Background: strip above the commander that has `"Choose a background"` in `keywords`.
    - Signature spell (Oathbreaker): strip above the oathbreaker planeswalker, linked by `signature_for_entry_id`.
    - Partner / Friends Forever / Survivors (symmetric `partner_scope` match): two side-by-side full tiles.

13. **Font:** deck name uses existing `--font-serif` (Newsreader). Cinzel is not loaded; no new web font added.

14. **Toast system:** build a minimal `useToast()` composable + single `<ToastHost />` Teleport mount as part of DB-3. No external library.

15. **Illegality red-shine classification (all 10 types):**
    - **Card-level** (shine on card): `banned_card`, `duplicate_card`, `color_identity_violation`, `not_legal_in_format`, `orphan_signature_spell`, `missing_signature_spell`.
    - **Deck-level** (shine on deck name): `deck_size`, `too_many_cards`, `invalid_commander`, `invalid_partner`.
    - Both use the same red-glow CSS class.

16. **Deck delete confirmation:** custom `ConfirmModal.vue` component (reusable). No browser `confirm()`.

17. **Tests:** vitest set up as part of DB-3. Frontend unit tests alongside each store/composable. Backend Pest/Phpunit tests alongside the prerequisite backend changes.

18. **Layout persistence:** tab layout persists globally (one layout across all decks) in localStorage under `vaultkeeper_tab_layout`. Group-collapse state persists per-deck-per-group under `vaultkeeper_deck_group_collapse`.

---

## Part 0 — Prerequisite backend slice (Laravel / PHP)

Must land before any frontend wiring that depends on it. Three independent changes; all with Pest tests alongside.

### 0.1 — `companion_scryfall_id` on `decks`

- Migration `api/database/migrations/2026_04_22_XXXXXX_add_companion_to_decks.php`:
  - Add `companion_scryfall_id` char(36) FK → `scryfall_cards.scryfall_id`, nullable, `nullOnDelete`. Place after `commander_2_scryfall_id`.
- `app/Models/Deck.php`: add to `$fillable`, add `companion()` relation (`belongsTo(ScryfallCard::class, 'companion_scryfall_id', 'scryfall_id')`).
- `DeckController.php`:
  - `store()` / `update()` validators: accept `companion_scryfall_id` (uuid|nullable|exists).
  - `presentDetail()` / `presentSummary()`: include `companion` field rendered via new `presentCompanion()` (same shape as `presentCommander()` but without the `commander_game_changer` field guaranteed).
  - Eager-load `'companion'` in show/update/store fresh reloads.
- `DeckLegalityService.php`: add a new check — if `companion_scryfall_id` is set, the card must exist as a `deck_entries` row in zone `main` or `side` with quantity ≥ 1, and its `keywords` must contain `"Companion"`. New illegality type `invalid_companion` added to the enum.
  - Migration to extend the `deck_ignored_illegalities.illegality_type` enum to include `invalid_companion`. Update `DeckLegalityController.php:14-25`.
- Tests:
  - `DeckControllerTest::test_creates_deck_with_companion()`
  - `DeckControllerTest::test_updates_companion_preserves_commanders()`
  - `DeckLegalityServiceTest::invalid_companion_cases` (DataProvider: companion not in deck; companion without Companion keyword; companion in maybe zone; happy path).

### 0.2 — `produced_mana` on `scryfall_cards`

- Migration `api/database/migrations/2026_04_22_XXXXXX_add_produced_mana_to_scryfall_cards.php`:
  - Add `produced_mana` JSON nullable after `color_identity`.
- `app/Models/ScryfallCard.php`: add to `$fillable` and cast as `array`.
- `app/Services/BulkSyncService.php`: map Scryfall's `produced_mana` array (values are `"W","U","B","R","G","C"`) into the new column. Update the bulk transform + the per-card import path.
- `DeckEntryController.php::presentEntry()`: include `produced_mana` on the embedded `scryfall_card` object (in addition to existing fields).
- Tests:
  - `BulkSyncServiceTest::test_produced_mana_is_stored_as_array()` (DataProvider: lands, dorks, fixers, non-producers).
  - `DeckEntryControllerTest::test_index_includes_produced_mana_on_card()`.

### 0.3 — `GET /api/collection/copies?scryfall_id={uuid}`

- Route in `api/routes/api.php` under the `auth:api` group: `Route::get('/collection/copies', [CollectionController::class, 'copiesForCard']);`.
- `CollectionController::copiesForCard()`:
  - Validates `scryfall_id` as required uuid.
  - Returns `CollectionEntry::where('user_id', auth()->id())->where('scryfall_id', $id)->with('location:id,name,type')->get()` mapped to `{id, quantity, condition, foil, location_id, location_name, notes}`.
- Tests:
  - `CollectionControllerTest::test_copies_for_card_returns_only_users_entries()`
  - `CollectionControllerTest::test_copies_for_card_includes_location_name()`
  - `CollectionControllerTest::test_copies_for_card_404_on_bad_uuid()` (validation failure).

### 0.4 — (implicit) existing illegality-type enum preservation

Double-check `api/database/migrations/2026_04_19_000008_create_deck_ignored_illegalities_table.php:24-26` — the migration pins the enum inline. Adding `invalid_companion` must ship with an `ALTER TABLE MODIFY COLUMN` migration, since MySQL enum changes are schema-level.

---

## Part 1 — Tab system infrastructure

### 1.1 Data model — `web/src/stores/tabs.js` (new Pinia store)

Binary-tree layout:

```js
// leaf
{ type: 'leaf', id: 'p1', tabs: [{ id: 't1', type: 'deck', label: 'Deck' }], activeIndex: 0 }
// split
{ type: 'split', id: 's1', direction: 'horizontal'|'vertical', splitAt: 0.6,
  left:  { ... }, right: { ... } }
```

- Panel/tab `id` are nanoid strings; stable across persistence.
- Persisted under `vaultkeeper_tab_layout` (JSON.stringify). Hydrated on app mount; falls back to a default layout `{type:'leaf', tabs:[{id,type:'deck',label:'Deck'}], activeIndex:0}` when missing/malformed.

Actions:
- `splitPanel(panelId, direction, newTab, placement)` — placement `'before'|'after'` decides which side holds the new tab. Replaces the leaf with a split node; child split positions default to 0.5.
- `moveTab(tabId, targetPanelId, targetIndex)` — remove-then-insert. If source panel becomes empty → collapse (see below).
- `closeTab(panelId, tabIndex)` — remove tab; if panel goes empty → `collapsePanel(panelId)` promotes sibling in the parent split.
- `resizePanel(splitId, newSplitAt)` — clamps to `[0.15, 0.85]`; enforces 200px min via computed pixel check at the component layer.
- `openTab(type, options)` — reuses an existing tab of the same type for `deck`/`side`/`maybe` (single-instance tabs edit shared state); allows multiple for `catalog`/`analysis`/`illegalities`.
- `undockSection('side'|'maybe')` — finds the Deck tab's leaf, splits it right (vertical), inserts the new `side`/`maybe` tab. Sets `deckView.{side|maybe}Undocked = true`.
- `redockSection('side'|'maybe')` — closes the matching tab (which triggers the generic collapse). Clears the undocked flag.

### 1.2 Components

- `web/src/components/tabs/TabSystem.vue` — root; renders tree recursively. Single instance mounted in the deck view.
- `web/src/components/tabs/PanelNode.vue` — recursive; dispatches to `SplitNode` or `LeafNode`.
- `web/src/components/tabs/SplitNode.vue` — two slots + drag resize handle (horizontal or vertical `<div class="resize-handle">`). Handle uses `pointerdown` + `pointermove`/`pointerup` on window; updates splitAt live; persists on up.
- `web/src/components/tabs/LeafNode.vue` — renders tab bar + active tab content. Drop zones on four inner edges (10% of width/height each) shown only while `dragOver === true`. Ghost preview is a semi-transparent overlay div.
- `web/src/components/tabs/TabBar.vue` — horizontal strip of draggable tab chips. Each chip has close button. Plus-button opens a tiny menu to add any available tab type.
- `web/src/components/tabs/tabRegistry.js` — maps `type → component`: `deck → DeckTabContent`, `catalog → CatalogPanel`, `analysis → AnalysisTab`, `illegalities → IllegalitiesTab`, `side → DeckTabContent (zone='side')`, `maybe → DeckTabContent (zone='maybe')`.

### 1.3 Drag-and-drop (HTML5 API)

- `dragstart` on tab chip: `dataTransfer.setData('application/vk-tab', JSON.stringify({tabId, sourcePanelId}))` + `effectAllowed='move'`.
- `dragover` on `LeafNode`: compute edge bucket (top/right/bottom/left/center) from `offsetX`/`offsetY` vs `clientWidth`/`clientHeight`; show the appropriate drop-zone highlight; `preventDefault()`.
- `drop` on `LeafNode`:
  - `center` → `moveTab(tabId, targetPanelId, -1)` (append).
  - `left/right` → `splitPanel(targetPanelId, 'horizontal', tab, placement)` then move tab into new half.
  - `top/bottom` → same with `'vertical'`.
- `dragover` on `TabBar` between two chips: drop inserts at that index via `moveTab`.

### 1.4 Tests (vitest)

`web/src/stores/__tests__/tabs.spec.js`:
- `splitPanel` horizontal + vertical produces correct tree.
- `moveTab` between leaves preserves activeIndex sensibly.
- `closeTab` on single-tab leaf collapses sibling up.
- `undockSection('side')` splits Deck leaf and inserts side tab right.
- `redockSection('side')` reverses the above.
- `openTab('deck', ...)` returns existing id when one exists (single-instance).
- `openTab('catalog', ...)` creates new each time (multi-instance).
- Persistence: after mutation, `localStorage` contains updated JSON; after manual corruption of storage, hydration falls back to default.

---

## Part 2 — Deck store (`web/src/stores/deck.js`)

```js
state: {
  deck: null,              // full deck object from GET /api/decks/{id}
  entries: [],             // deck entries with embedded scryfall_card
  ownedCopiesByScryfallId: {}, // { [uuid]: CollectionEntry[] } for physical-copy dropdown
  illegalities: [],
  loading: false,
  saving: new Set(),       // entry ids currently being patched (for in-flight UI)
  activeEntryId: null,
  pendingRollbacks: [],    // history for optimistic rollback
}

getters:
  entriesByZone(main|side|maybe)
  commanderEntries()       // filtered by is_commander from entries
  signatureSpellEntries()  // is_signature_spell=true
  companionEntry()         // the single entry matching deck.companion_scryfall_id
  categoriesInDeck()       // distinct category strings from main-zone entries
  activeIllegalities()     // illegalities where ignored === false
  cardLevelIllegalitiesByScryfallId()
  deckLevelIllegalities()  // see classification in decision 15

actions:
  loadDeck(id)             // GET /api/decks/{id}  (the show() endpoint returns full detail + entries)
  loadEntries(id)          // GET /api/decks/{id}/entries with sort=name
  loadIllegalities(id)     // GET /api/decks/{id}/illegalities
  loadOwnedCopies(scryfallId) // GET /api/collection/copies?scryfall_id=...
  addEntry(deckId, payload)
  updateEntry(deckId, entryId, patch)
  removeEntry(deckId, entryId)
  moveEntryZone(deckId, entryId, zone)        // thin wrapper around updateEntry
  updateDeck(id, patch)                       // PUT /api/decks/{id}
  setCompanion(id, scryfallId)                // PUT deck with companion_scryfall_id
  setCommander(slot, scryfallId)              // PUT deck commander_{slot}_scryfall_id
  ignoreIllegality(id, payload)               // POST /illegalities/ignore
  unignoreIllegality(id, payload)             // POST /illegalities/unignore (NOT DELETE)
  setActiveEntry(entryId)
```

### Optimistic update pattern

```js
async function optimistic(mutateLocal, apiCall, rollback) {
  const snapshot = mutateLocal();           // returns undo fn or snapshot
  try { const result = await apiCall(); reconcile(result); }
  catch (err) { rollback(snapshot); toast.error(err.message || 'Update failed'); }
}
```

All four entry mutations (add/update/remove/moveZone) go through this helper. `reconcile(result)` replaces the local entry object with the server response so backend-derived fields (`owned_copies`, `available_copies`, `oracle_tags`, auto-assigned `category`) stay consistent.

Illegalities are re-fetched after every successful mutation (cheap — single endpoint, no client recomputation). Deck `color_identity` is re-fetched after commander changes via `loadDeck`.

### Tests (vitest) — `web/src/stores/__tests__/deck.spec.js`

- `addEntry` happy path merges server response into `entries`.
- `addEntry` failure path restores prior state and emits toast.
- `updateEntry` for zone move: entry appears in new zone getter, gone from old.
- `removeEntry` deletes row; failure re-inserts with same id.
- `setCompanion` updates deck.companion_scryfall_id and triggers illegality reload.
- `ignoreIllegality` marks it ignored locally then reconciles from server.
- DataProvider-style tests for `cardLevelIllegalitiesByScryfallId` across all 10 illegality types.
- Mock `fetch` via vitest spy; no real network.

---

## Part 3 — Route and view wiring

### 3.1 Router (`web/src/router/index.js`)

- Add `{ path: '/collection/decks/:id(\\d+)', name: 'deck', component: DeckView, meta: { requiresAuth: true }, props: true }`.
- Remove the `/catalog` route (TODO from `router/index.js:43-50`).
- `/collection` continues to render `CollectionView.vue` for the location (drawer/binder) case.

### 3.2 `web/src/views/DeckView.vue` (new)

- Three-column grid exactly like `CollectionView.vue:42-88`:
  - Column 1: `<LocationSidebar>` (unchanged — see Part 8 for sidebar updates that also affect CollectionView).
  - Column 2: `<TabSystem>` (Part 1).
  - Column 3: `<DeckDetailSidebar v-if="deck.activeEntryId">` (Part 7).
- On mount and on `route.params.id` change: `deck.loadDeck(id)` then `deck.loadEntries(id)` then `deck.loadIllegalities(id)` in parallel. Show a skeleton while `deck.loading`.
- On unmount: `deck.$reset()` to avoid leaking state across deck switches.
- 404 handling: if `loadDeck` returns 404, redirect to `/collection`.

### 3.3 `CollectionView.vue`

No structural change. It still renders the card-list panel for location-type views. The old conditional "if location.type is deck, render…" from the original plan is dropped — the new route handles it.

---

## Part 4 — `DeckTabContent.vue`

Props: `zone?: 'side'|'maybe'` — when set, renders only that zone's grid (used for undocked tabs). Otherwise renders the full deck layout.

Files:
- `web/src/components/deck/DeckTabContent.vue` — top-level layout.
- `web/src/components/deck/DeckInfoPanel.vue` — top-left: name, format badge, color pips, card count, description, edit button.
- `web/src/components/deck/CommanderZone.vue` — top-right: commander tiles + companion/background/signature strips.
- `web/src/components/deck/CommanderStrip.vue` — the expand-on-hover card strip (used for companion above cmdr1, background above "choose-a-background" commander, signature spell above oathbreaker).
- `web/src/components/deck/DeckFilterBar.vue` — search, group-by, sort, display mode.
- `web/src/components/deck/DeckGrid.vue` — the zone grid with grouped/sortable cards, drag-drop.
- `web/src/components/deck/ZoneDivider.vue` — side/maybe divider with undock button.

### 4.1 `CommanderZone.vue` logic

```js
const slot1 = deck.commander1     // ScryfallCard object or null
const slot2 = deck.commander2
const companion = deck.companionEntry     // from getter; may be null
const signatureSpells = deck.signatureSpellEntries  // Oathbreaker only

// Detection helpers (pure):
function isBackground(card)   { return (card.subtypes||[]).includes('Background') }
function hasChooseBackground(card) { return (card.keywords||[]).includes('Choose a background') }

// Layout rules:
// - If slot2 is a Background and slot1 has 'Choose a background':
//     render as: [companion strip][background strip][slot1 full tile], slot2 is NOT rendered separately
// - Else:
//     render as: [companion strip][slot1 full tile]  [slot2 full tile]
//     (Partner / Friends Forever / Survivors all land here — symmetric tiles)
// - For format='oathbreaker':
//     slot1 is the oathbreaker planeswalker; render signatureSpellEntries as strip above it
// - For format in ['standard','modern','pauper']: do not render CommanderZone at all
```

`CommanderStrip.vue`: an inline card strip (reusing the same layered-strip aesthetic as `CardStrip.vue`) that expands to a full tile on hover. While the strip is expanded, the underlying commander tile collapses to a reduced height (CSS transition). Strip height ≈ 40px collapsed, full tile on hover.

Empty slot renders a dashed placeholder tile with "+ Add Commander" / "+ Add Companion" — clicking it opens a new Catalog tab with a pre-applied search (`is:commander` for commander slots, `is:companion` for companion slot).

### 4.2 `DeckInfoPanel.vue`

- Deck name: `<h2 class="deck-name" :class="{ 'illegal-glow': hasDeckLevelIllegality }">`
- Format badge: existing `format` value (`commander`, `oathbreaker`, etc.).
- Color identity pips: render `deck.color_identity` string (e.g., `"WUB"`) letter-by-letter through `<ManaSymbol>`.
- Card count: sum of `quantity` in main-zone entries.
- Description: 2-line clamp; click to expand.
- Edit button: opens existing `LocationModal` (extended in Part 9).
- Clicking a red-glowing deck name opens the Illegalities tab via `tabs.openTab('illegalities')`.

### 4.3 `DeckFilterBar.vue`

Filter state lives on the `deck` store (`state.view = { search, groupBy, sort, displayMode }`) so it survives undock/redock. Options:

- Search: debounced 150ms, client-side string match on `name`.
- Group by: `full | categories | type | color | cmc | rarity | zone`.
- Sort: `name | cmc | color | rarity | category`.
- Display mode: `strips | tiles`.

### 4.4 `DeckGrid.vue`

Renders each group as a collapsible section:
- Header: `{groupName} ({count})`, collapse chevron.
- Collapse state persisted in `localStorage[vaultkeeper_deck_group_collapse][deckId][groupKey]`.
- Body: flex-packed `<CardStrip>` (strips mode, reusing `CardStrip.vue`) or `<CardTile>` (tiles mode, reusing `CardTile.vue`). Both components already exist from DB-2.

#### Category group-by drag-drop
- Each group header has `@drop` handler. `dragover` highlights target.
- On drop: `deck.updateEntry(entry.id, { category: targetCategory })`.
- Drag source: existing HTML5 drag payload on `CardTile.vue:88-95`. Extend payload with `{source:'deck', deckEntryId}` when dragged from inside the deck.

#### Drag-to-catalog removes entry
- The Catalog tab leaf accepts drops with `source:'deck'` payload. On drop: `deck.removeEntry(deckId, deckEntryId)`.
- Does NOT cross zones — a card in side + maybe has two entries; dragging the main-zone strip only removes the main row.

#### Sideboard / Maybeboard
- When `!sideUndocked`: render sideboard below main, separated by `<ZoneDivider zone="side">` which shows the ↑ undock button. Undock button calls `tabs.undockSection('side')`.
- When `sideUndocked`: main deck renders without the sideboard section. The undocked `side` tab renders the sideboard content using `DeckTabContent` with `zone='side'`.
- Same applies to maybe.
- Undocked tab has a ↓ redock button in its tab header (via `TabBar.vue` checking for `tab.type in ['side','maybe']`). Closing the tab also redocks (via `tabs.closeTab` → `tabs.redockSection` hook).

### 4.5 Card interactions

- **Right-click context menu:** Mount a `<CardContextMenu>` component near `document.body` via `<Teleport to="body">`. Entries: Move to Main/Side/Maybe (current zone disabled), Quantity +1/−1, Set quantity… (prompt), Remove, View details.
- **Click:** `deck.setActiveEntry(entry.id)` opens detail sidebar.
- **GC badge:** when `deck.format === 'commander'` and `entry.scryfall_card.commander_game_changer === true`:
  - Tile mode: bottom-center pill badge (new `.gc-badge` class in `CardTile.vue`, gold gradient).
  - Strip mode: small pill badge left of mana cost in the overlay bar.
- **Illegality red shine:** Extend `CardStrip.vue` and `CardTile.vue` to accept an `illegal` boolean prop. When true, add `.illegal-glow` class — new CSS with `box-shadow: 0 0 0 2px rgba(209,90,74,.6), 0 0 18px 4px rgba(209,90,74,.35)`. Distinct from the ownership `shine-*` classes (they can stack).
- **Foil toggle:** placeholder disabled button in detail sidebar; inline TODO comment referencing the follow-up (foil column on `deck_entries`).

### 4.6 Tests (vitest)

- `DeckGrid.spec.js`: group-by categories renders distinct groups with correct counts; drag into a group emits updateEntry patch.
- `CommanderZone.spec.js`: background detection flips to stacked layout; companion strip renders above slot1; oathbreaker signature spell renders above slot1.
- `DeckFilterBar.spec.js`: state writes into deck store; search is debounced.
- `DeckInfoPanel.spec.js`: illegal-glow class toggles on deck-level illegality presence.

---

## Part 5 — `AnalysisTab.vue`

`web/src/components/deck/AnalysisTab.vue` — client-side stats from `deck.entries` (main zone only). No external charting library.

### 5.1 Computations (pure helpers in `web/src/utils/deckStats.js`)

- `costPipsByColor(entries)` — iterate each entry's `scryfall_card.mana_cost`, regex-split on `\{[^}]+\}`, increment W/U/B/R/G/C counters. Hybrid pips like `{W/U}` count 0.5 to each. `{2/W}` counts 0.5 to W + 0.5 to generic (ignored). Multiply by `quantity`.
- `producerCardsByColor(entries)` — for each entry whose `scryfall_card.produced_mana` array is non-empty OR whose `oracle_tags` contains one of `['ramp','mana-rock','mana-dork']` OR whose `type_line` contains `'Land'`, bucket by each color in `produced_mana` (fallback to `color_identity` when `produced_mana` is null for pre-sync cards). Weight by `quantity`.
- `cmcBuckets(entries)` — exclude lands (`type_line.includes('Land')`). MDFC cards with one land face: use the front face's CMC (scryfall reports 0 for land-side). Buckets: 0,1,2,3,4,5,6,7,8+. Weighted by `quantity`.
- `avgManaValue(entries)` — mean CMC, excluding lands, weighted by quantity.
- `totalManaValue(entries)` — sum of CMC × quantity, excluding lands.

### 5.2 Component layout

Top section — "Cost" and "Production" horizontal progress bars for overall distribution across WUBRG+C (each bar sums to 100%, inline legend).

Per-color row (6 cells: W U B R G C):
- Mana symbol SVG (large).
- Cost % vertical or horizontal bar + numeric pip count.
- Production % bar + producer count.

Curve chart — simple SVG. 9 bars for 0..8+. Count label above each bar. Y axis dynamic to max. "Avg mana value: X.XX · Total mana value: XXX" caption.

### 5.3 Tests (vitest) — `web/src/utils/__tests__/deckStats.spec.js`

DataProvider-driven:
- Fixture decks with known compositions; assert expected pip counts, producer buckets, CMC histogram, avg/total.
- Hybrid pips (`{W/U}`) — test 0.5/0.5 split.
- MDFC cards (land back face) — test CMC exclusion.
- Deck with a card missing `produced_mana` (null) — falls back to `color_identity`.
- Deck with no main entries — zero-state handled without divide-by-zero.

---

## Part 6 — `IllegalitiesTab.vue`

`web/src/components/deck/IllegalitiesTab.vue`.

### 6.1 Data

Reads `deck.illegalities` (loaded by `DeckView` on mount; re-fetched after every deck mutation via the optimistic reconcile step). No additional fetch on tab open — the store is already fresh.

### 6.2 Layout

- Summary banner at top:
  - If `activeIllegalities.length === 0`: green check + "Deck is legal".
  - Else: `"{n} active illegalities · {m} ignored"`.
- List rows sorted by `ignored` asc then `illegality_type` then card name.

Each row:
- `<input type="checkbox" :checked="row.ignored" @change="toggle(row)">`
- Human label (lookup table `illegalityLabel(type)`):
  - `banned_card` → "Banned card"
  - `color_identity_violation` → "Color identity violation"
  - `duplicate_card` → "Singleton violation"
  - `invalid_partner` → "Invalid partner pairing"
  - `invalid_commander` → "Invalid commander"
  - `invalid_companion` → "Invalid companion"
  - `deck_size` → "Deck size"
  - `too_many_cards` → "Too many copies"
  - `not_legal_in_format` → "Not legal in format"
  - `orphan_signature_spell` → "Orphan signature spell"
  - `missing_signature_spell` → "Missing signature spell"
- Description text from the payload (backend already provides `description`).
- Card name if applicable (`scryfall_id_1` + embedded card data); clicking a card name calls `tabs.openTab('deck')` then `deck.setActiveEntry(entryId)` (lookup entry by scryfall_id in current zone).
- Ignored rows: `text-decoration: line-through; color: var(--vk-fg-dim);`.

### 6.3 Toggle handler

```js
async function toggle(row) {
  const payload = {
    illegality_type: row.type,
    scryfall_id_1: row.scryfall_id_1 ?? null,
    scryfall_id_2: row.scryfall_id_2 ?? null,
    oracle_id: row.oracle_id ?? null,
    expected_count: row.ignored ? null : row.expected_count  // unignore drops expected_count
  }
  row.ignored
    ? await deck.unignoreIllegality(deckId, payload)
    : await deck.ignoreIllegality(deckId, payload)
}
```

### 6.4 Tests (vitest)

- Row renders line-through when ignored.
- Clicking the checkbox calls the correct store action with the right payload shape.
- Card-name link sets active entry and switches to deck tab.
- Zero-active state shows green banner.

---

## Part 7 — `DeckDetailSidebar.vue`

`web/src/components/deck/DeckDetailSidebar.vue` — parallel to `DetailSidebar.vue:1-240` (collection) and `CatalogDetailSidebar.vue:1-210` (catalog).

Shown when `deck.activeEntryId !== null`. Reads `entry = deck.entries.find(e => e.id === activeEntryId)`.

### 7.1 Structure

```vue
<aside class="deck-detail-sidebar">
  <CardDetailBody :card="entry.scryfall_card" />

  <section class="deck-context">
    <ZoneSelector :value="entry.zone" @change="deck.moveEntryZone(deckId, entry.id, $event)" />
    <CategoryInput :value="entry.category" :suggestions="deck.categoriesInDeck"
                   @commit="patch({category: $event})" />
    <QuantityStepper :value="entry.quantity"
                     @dec="patch({quantity: Math.max(1, entry.quantity - 1)})"
                     @inc="patch({quantity: entry.quantity + 1})" />
    <PhysicalCopyDropdown :scryfallId="entry.scryfall_id"
                          :value="entry.physical_copy_id"
                          @select="patch({physical_copy_id: $event})" />
    <button disabled title="Foil toggle lands after deck_entries.foil migration">Foil</button>
    <button class="danger" @click="remove()">Remove from deck</button>
  </section>

  <section v-if="entry.scryfall_card.wanted_by_decks?.length">
    <!-- reuse the same block as DetailSidebar.vue:88-96 -->
  </section>
</aside>
```

### 7.2 `ZoneSelector.vue`

Three-button segmented control: Main / Side / Maybe. Disabled for commander entries (is_commander=true).

### 7.3 `CategoryInput.vue`

Text input with dropdown of suggestions fed by `deck.categoriesInDeck`. Commit on blur or Enter. Empty string clears the category.

### 7.4 `QuantityStepper.vue`

−/+ buttons plus numeric input. Format-aware: Commander format clamps at 1 for non-basic-land entries (optional hint in tooltip; doesn't block — backend still validates).

### 7.5 `PhysicalCopyDropdown.vue`

- On mount (and whenever `scryfallId` changes): `deck.loadOwnedCopies(scryfallId)` which calls the new `GET /api/collection/copies?scryfall_id={uuid}` endpoint.
- Dropdown options: each collection_entry rendered as `"{location_name} · qty {n}{ foil ? ' ·F' : '' }"` with the entry id as the value. First option: `"— none —"` (clears the binding).
- On select: emits the collection_entry id; parent calls `patch({physical_copy_id: id})`.

### 7.6 GC badge / illegality shine in sidebar

- GC badge shown on `CardDetailBody` when `deck.format === 'commander'` AND `card.commander_game_changer`. Re-use the new `.gc-badge` class from Part 4.5. (Adding a single conditional render inside `CardDetailBody.vue` is acceptable — it's a pure visual flag that doesn't change the component's purpose and matches the existing `commander_game_changer` field it already has access to.)
- Red-glow on card image if the entry appears in `cardLevelIllegalitiesByScryfallId`.

### 7.7 Tests (vitest)

- `DeckDetailSidebar.spec.js`: mounts when activeEntryId is set; zone change fires moveEntryZone; remove fires removeEntry; physical-copy dropdown calls loadOwnedCopies on scryfallId change.
- `CategoryInput.spec.js`: suggestion list filtered by input prefix; commit on blur.
- `PhysicalCopyDropdown.spec.js`: renders options from store; emits selected id on change; default `—none—` clears.

---

## Part 8 — Sidebar: decks mixed with locations

### 8.1 Data layer

- Extend the `collection` store (`web/src/stores/collection.js`) to also hold `decks`:
  - `state.decks: []`
  - `fetchDecks()` — `GET /api/decks`; stores into `state.decks`.
  - `fetchAll()` — parallel `fetchGroups()`, `fetchLocations()`, `fetchDecks()`. Called on app mount replacing the current `fetchLocations()` call.
- `sidebarItems` getter (existing at `collection.js`) extended: within each group, merge `locations` and `decks` by `sort_order`, producing items of kinds `'group' | 'location' | 'deck'`.

### 8.2 Rendering in `LocationSidebar.vue`

- New branch for `kind === 'deck'` that renders:
  ```vue
  <router-link :to="{name: 'deck', params: {id: deck.id}}" class="sidebar-deck"
               :class="{ 'illegal-glow': deck.illegality_count > 0 }">
    <IconDeck />
    <span class="name">{{ deck.name }}</span>
    <span class="format-badge">{{ formatShort(deck.format) }}</span>
    <ManaPips :identity="deck.color_identity" size="xs" />
    <span class="count">{{ deck.entry_count }}</span>
  </router-link>
  ```
- `formatShort('commander') === 'CMDR'`, `'oathbreaker' === 'OATH'`, etc.
- `deck.illegality_count > 0` triggers the red-glow class (same `.illegal-glow` as Part 4).
- Active deck highlighted by comparing `route.params.id == deck.id`.

### 8.3 Drag-drop for decks

`vuedraggable` (already used for locations at `LocationSidebar.vue:148`) is type-agnostic — it works on the merged list. On drag end:
- If item is a deck: `PUT /api/decks/{id}` with `{ group_id, sort_order }`.
- If item is a location: existing `collection.reorderAll()` path.
- New helper `reorderSidebarItems(group)` that batches updates in one loop and persists. Backend already accepts `group_id` + `sort_order` on `PUT /api/decks/{id}` (`DeckController.php:110-123`). No backend change.

### 8.4 Tests (vitest)

- `sidebarItems` getter: given mixed locations and decks in a group, returns them interleaved by sort_order.
- Clicking a deck link sets route to `/collection/decks/{id}`.
- Drag a deck to a new group sends the correct PUT request.
- Illegal-glow class appears when `illegality_count > 0`.

---

## Part 9 — Extend `LocationModal.vue` for deck creation

`web/src/components/LocationModal.vue:1-255` already has a Drawer / Binder / Deck type picker at lines 86-129. Extend the deck branch with format + commander fields.

### 9.1 Form state split by type

Current `form` reactive already holds `{ type, name, description }`. Extend:

```js
const drawerForm = reactive({ name: '', description: '' })
const binderForm = reactive({ name: '', description: '' })
const deckForm   = reactive({
  name: '', description: '',
  format: 'commander',
  commander_1_scryfall_id: null, commander_2_scryfall_id: null,
  companion_scryfall_id: null
})
const activeType = ref(props.location?.type ?? 'drawer')
const activeForm = computed(() => ({drawer:drawerForm, binder:binderForm, deck:deckForm}[activeType.value]))
```

Each type picker button sets `activeType`. Switching back restores the matching form's values (state preserved).

### 9.2 Deck-specific fields (shown when activeType === 'deck')

- **Format dropdown:** `commander | oathbreaker | pauper | standard | modern`.
- **Commander autocomplete slots (only when format in [commander, oathbreaker]):**
  - Slot 1: always shown.
  - Slot 2: shown only for `commander` format.
  - Autocomplete uses existing search endpoint: `GET /api/scryfall-cards/search?q={query}&per_page=10`. Append `t:legendary (creature OR planeswalker)` to the query for commander format; `t:planeswalker` for oathbreaker.
  - Debounced 200ms. Dropdown shows `{name} · {mana_cost} · {set_code.toUpperCase()}`.
- **Companion slot (only when format === 'commander'):**
  - Same autocomplete shape but with `q` prefix `is:companion` OR `keyword:Companion` (use whichever the existing CardSearchService supports — per audit: `keyword:Companion` via `JSON_OVERLAPS` at `CardSearchService.php:1182`).

### 9.3 Submit

`submit()` dispatches by `activeType`:
- `drawer`/`binder` → existing `collection.createLocation({type, name, description})`.
- `deck` → new `collection.createDeck(deckForm)` → `POST /api/decks` with `{ name, format, description, commander_1_scryfall_id, commander_2_scryfall_id, companion_scryfall_id }`. After success: sidebar refreshes via `collection.fetchDecks()`; navigate to `/collection/decks/{newId}` so the user lands in the empty deck.

### 9.4 Edit mode

When editing an existing deck (`props.location?.type === 'deck'`), populate `deckForm` from the deck object. On submit: `PUT /api/decks/{id}`.

The same modal handles edits from:
- Sidebar context menu on a deck.
- "Edit" button in `DeckInfoPanel` (Part 4).

### 9.5 Tests (vitest)

- Switching type between drawer/binder/deck preserves each form's state.
- Format `commander` shows 2 commander slots; `oathbreaker` shows 1 planeswalker slot; `standard` shows neither.
- Commander autocomplete debounces and calls `/scryfall-cards/search` with correct `q`.
- Submit with type=deck POSTs to `/api/decks` with expected payload.
- Edit mode pre-fills the deck form.

---

## Part 10 — Shared utilities

### 10.1 Toast system

- `web/src/composables/useToast.js` — exposes `toast.success(msg)`, `toast.error(msg)`, `toast.info(msg)`. Internally pushes into a module-level reactive array with auto-dismiss after 4s.
- `web/src/components/ToastHost.vue` — `<Teleport to="body">` + transition group rendering the reactive array. Mount once in `App.vue`.
- Tests: pushing a toast adds to array; auto-dismiss removes after timer.

### 10.2 `ConfirmModal.vue`

- Reusable modal: title, message, confirm/cancel buttons, destructive variant.
- `web/src/composables/useConfirm.js` — imperative helper `const ok = await confirm({title, message, destructive: true})`.
- Used by deck delete, possibly future destructive flows.

### 10.3 Font usage

- No new web font. Deck name uses `font-family: var(--font-serif);` (Newsreader). Size ~28px, weight 500.

### 10.4 CSS additions (`web/src/style.css`)

```css
.illegal-glow {
  box-shadow:
    0 0 0 2px rgba(209,90,74,.65),
    0 0 18px 4px rgba(209,90,74,.35);
  animation: illegal-pulse 2.4s ease-in-out infinite;
}
@keyframes illegal-pulse {
  0%, 100% { box-shadow: 0 0 0 2px rgba(209,90,74,.5), 0 0 14px 3px rgba(209,90,74,.25); }
  50%      { box-shadow: 0 0 0 2px rgba(209,90,74,.8), 0 0 22px 6px rgba(209,90,74,.45); }
}
.gc-badge {
  background: linear-gradient(135deg, #f0c35c, #c99d3d);
  color: #0f0e0b;
  font-size: 10px; font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
}
```

### 10.5 Vitest setup

- Add `web/package.json` scripts: `"test": "vitest"`, `"test:run": "vitest run"`.
- Add dev deps: `vitest`, `@vue/test-utils`, `@vitest/ui` (optional), `happy-dom`.
- `web/vitest.config.js`: extend `vite.config.js`, `test.environment = 'happy-dom'`, `test.globals = true`.
- CI: surface in existing Docker/CI if applicable (README mentions backend tests; frontend tests are new).

---

## Tests — master list

### Backend (Pest/PHPUnit, alongside Part 0)

- `DeckControllerTest`
  - `test_creates_deck_with_companion()`
  - `test_updates_companion_preserves_commanders()`
  - `test_stores_color_identity_reflects_background_pairing()`
- `DeckLegalityServiceTest`
  - DataProvider `invalid_companion_cases`
  - Regression: existing 6 check types still pass after enum extension.
- `BulkSyncServiceTest::test_produced_mana_is_stored_as_array()`
- `DeckEntryControllerTest::test_index_includes_produced_mana_on_card()`
- `CollectionControllerTest::copies_for_card` (three cases — listed in Part 0.3).

### Frontend (vitest, alongside each UI part)

Listed per-part above. Summary — new spec files:

- `web/src/stores/__tests__/tabs.spec.js`
- `web/src/stores/__tests__/deck.spec.js`
- `web/src/stores/__tests__/collection.spec.js` (sidebarItems getter for merged list)
- `web/src/utils/__tests__/deckStats.spec.js`
- `web/src/components/deck/__tests__/CommanderZone.spec.js`
- `web/src/components/deck/__tests__/DeckGrid.spec.js`
- `web/src/components/deck/__tests__/DeckInfoPanel.spec.js`
- `web/src/components/deck/__tests__/DeckFilterBar.spec.js`
- `web/src/components/deck/__tests__/DeckDetailSidebar.spec.js`
- `web/src/components/deck/__tests__/CategoryInput.spec.js`
- `web/src/components/deck/__tests__/PhysicalCopyDropdown.spec.js`
- `web/src/components/deck/__tests__/IllegalitiesTab.spec.js`
- `web/src/components/__tests__/LocationModal.spec.js` (deck type branch)
- `web/src/components/tabs/__tests__/LeafNode.spec.js` (drop-zone edge computation)
- `web/src/composables/__tests__/useToast.spec.js`
- `web/src/composables/__tests__/useConfirm.spec.js`

---

## Runbook / Implementation order

Strict ordering where there are dependencies; otherwise parallelizable.

1. **Part 0.1–0.4 backend** (migrations + model + validators + tests). Ship before any frontend code that depends on `companion_scryfall_id`, `produced_mana`, or `/collection/copies`.
2. **vitest setup** (Part 10.5) — unblocks every frontend test.
3. **Part 10.1 (toast) + Part 10.2 (confirm modal)** — tiny, needed by later parts.
4. **Part 2 (deck store)** — depends on Part 0 being deployed.
5. **Part 1 (tab system)** in parallel with Part 2. Independent.
6. **Part 3 (route + DeckView)** — depends on 1 and 2.
7. **Part 4 (DeckTabContent + children)** — depends on 3.
8. **Part 5 (AnalysisTab)**, **Part 6 (IllegalitiesTab)**, **Part 7 (DeckDetailSidebar)** — all depend on 4 but can be built in parallel.
9. **Part 8 (sidebar decks)** — depends on 3 for navigation; can start once `collection.fetchDecks()` is wired.
10. **Part 9 (LocationModal extension)** — depends on 0 and 8.

---

## Verification

### Backend smoke

```bash
TOKEN=...   # JWT from /api/auth/login

# Decks list — confirm companion field present
curl -sS http://localhost:8080/api/decks \
  -H "Authorization: Bearer $TOKEN" | jq '.[0] | {name, format, color_identity, companion: .companion?.scryfall_id}'

# Produced_mana visible on deck entries
curl -sS http://localhost:8080/api/decks/1/entries \
  -H "Authorization: Bearer $TOKEN" | jq '.[0].scryfall_card.produced_mana'

# Copies endpoint
curl -sS 'http://localhost:8080/api/collection/copies?scryfall_id=<uuid>' \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# Illegalities listing
curl -sS http://localhost:8080/api/decks/1/illegalities \
  -H "Authorization: Bearer $TOKEN" | jq '.[] | {type, ignored}'
```

### Frontend checklist (manual)

1. Visit `/collection/decks/1` — three-column layout, tab system renders with a single Deck tab.
2. Deck info panel shows name in Newsreader, format badge, color pips, card count.
3. Commander zone:
   - Commander format w/ partners: two equal tiles side-by-side.
   - Commander format w/ Background: background renders as strip above its creature.
   - Commander format w/ companion set: companion renders as strip above slot 1.
   - Oathbreaker: signature spell as strip above the planeswalker.
   - Standard/Modern/Pauper: no commander zone rendered.
4. Group by Categories → collapsible headers with counts; collapse state persists per deck.
5. Drag a card between category groups → category PATCHes, group membership updates optimistically.
6. Right-click a card → zone-move / qty / remove menu.
7. Click a card → `DeckDetailSidebar` opens with zone selector, category input with autocomplete, quantity stepper, physical-copy dropdown populated from `/collection/copies`.
8. Open a Catalog tab → `CatalogPanel` renders with format + color-identity pills visible.
9. Drag from Catalog onto Deck tab → card appears in main zone; toast on success, toast on failure with rollback.
10. Drag a deck card onto Catalog tab → entire row removed for that zone; other zones' rows unaffected.
11. Analysis tab → color pip breakdown + production bars + CMC chart render with numeric match to manual count.
12. Illegalities tab → list renders; checkbox toggles persist after reload; re-appearance after deck change verified.
13. Illegal card → red glow visible on strip/tile AND card image in sidebar.
14. Deck-level illegality (e.g., 99-card Commander deck) → red glow on deck name in info panel AND in sidebar link.
15. Undock sideboard → splits panel vertically, side tab appears; ↓ redock button visible; closing tab redocks.
16. Resize split → smooth, persists on reload.
17. Open "+ New Location" → Deck type shows format dropdown + commander + companion autocompletes; submitting navigates to new deck.
18. GC badge visible on game-changer cards only when format is Commander.
19. Reload page → tab layout persists; active deck persists via URL; group-collapse state persists per-deck.
20. Delete deck from sidebar → custom confirm modal; cancel preserves deck; confirm removes deck and navigates to `/collection`.

---

## Out of scope for DB-3

- Import / export (Moxfield text, Arena, MTGO). **Follow-up: DB-4 Deck Import/Export.**
- Playtester / sample hand simulator. **Follow-up: DB-5 Playtester.**
- Price tracking. **Follow-up: DB-6 Price Overlay.**
- Scryfall-syntax parser in the collection-view filter bar. **Follow-up: DB-7 Collection Scryfall Syntax.**
- CardStrip DFC wire-up in collection view (DB-2 follow-up).
- `deck_entries.foil` column and UI toggle (DB-2 follow-up — foil button is rendered disabled in DB-3).
- Companion legality enforcement beyond the `invalid_companion` presence-check (companion deck-construction restrictions per card's specific text are intentionally not validated).
