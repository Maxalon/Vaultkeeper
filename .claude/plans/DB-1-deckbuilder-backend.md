# DB-1 — Deckbuilder Backend

Backend data model, legality engine, and API endpoints for the deckbuilder. Frontend ships in DB-2.

Formats supported: Commander, Oathbreaker, Pauper, Standard, Modern.

---

## Decisions locked in during planning

1. **Remove `user_cards`** in this session. It's redundant with `scryfall_cards` (every field duplicates it except `last_scryfall_sync`, which is an obsolete sync cache). Repoint FKs from `collection_entries.scryfall_id` and `deck_entries.scryfall_id` at `scryfall_cards.scryfall_id`. Single source of truth.

2. **Commander source of truth:** `decks.commander_1_scryfall_id` / `decks.commander_2_scryfall_id`. Entries are mirrored for UI (they show in the deck view), but the deck columns are authoritative. `deck_entries.is_commander` is a view-convenience boolean, not a write target — controllers set it based on the deck columns.

3. **Signature spell (Oathbreaker):** legality engine flags orphans, never mutates entries. We add `deck_entries.is_signature_spell` (bool) and `deck_entries.signature_for_entry_id` (nullable self-FK → deck_entries, no cascade). If the oathbreaker entry is deleted, the signature spell becomes an orphan and is flagged as illegal; user decides the replacement.

4. **Partner detection** via derived `partner_scope` column on scryfall_cards. Parse from `oracle_text` at sync time:
   - `null` — not a partner
   - `'plain'` — keyword `Partner` present, no `Partner—X` suffix (includes Partner with X, which also grants plain Partner)
   - `'friends_forever'`, `'survivors'`, `'character_select'` — derived via regex `Partner[\x{2014}-]([A-Za-z ]+?)\s*\(` and `Str::snake`
   - any future `Partner—Foo` variant gets picked up automatically
   Asymmetric pairings (Doctor's companion ↔ subtype `Doctor`, Choose a background ↔ subtype `Background`) stay in the legality engine as two small rules. No partner column beyond `partner_scope`.

5. **`scryfall_cards_raw` table** (new) stores `all_parts` JSON per card. Used for the Partner-with UI suggestion ("you picked Pir → suggest Toothy"), and available for future token/meld/combo-piece features. Kept separate so `scryfall_cards` doesn't bloat.

6. **Auto-category allowlist** uses the existing `config('scryfall.oracle_tags')` array (26 tags: ramp, draw, removal, etc.). User-created categories are free-form strings layered on top.

7. **Wanted response shape:** bool → enum(`'main'`, `'side'`, `'maybe'`, null). `CollectionController::wantedByDecksMap()` is updated to emit `{deck_id: {name, zone}}` objects instead of a flat id list, so the frontend can render the zone context alongside the deck name.

8. **Elasticsearch deferred.** SQL with hash-joined aggregates handles the deckbuilder's read load (100 cards per deck, <1k decks per user). ES revisited later for card search if/when we hit a real perf wall.

9. **`commander_game_changer`** is per-printing — single boolean column on `scryfall_cards`, populated from Scryfall's `game_changer` field during bulk sync.

10. **type_line parsing** — use `hasSubtype(card, 'Doctor')` / `hasSubtype(card, 'Background')` helper on the legality service. Splits on em-dash, tokenizes by whitespace, exact match on tokens. No parsed-subtypes column in this session (defer to card-search session).

11. **Unignore uses `POST .../unignore`** (not `DELETE` with body) to avoid the known issue of some HTTP clients and proxies stripping request bodies from DELETE requests.

12. **Tests** via DataProvider patterns, coverage ≥1 function per code path. Heavy focus on `DeckLegalityService::check()` since it's the highest-surface-area logic.

13. **Existing `apiResource('decks', ...)` in routes/api.php** gets replaced in place with explicit route lines so we avoid doubled routes.

---

## Part 1 — Migrations

Ordered so each step can apply cleanly on top of the previous. Every migration is reversible (`down()` implemented).

### 1.1 Add `commander_game_changer` + `partner_scope` to `scryfall_cards`

```
ALTER scryfall_cards
  ADD commander_game_changer BOOLEAN NOT NULL DEFAULT FALSE,
  ADD partner_scope          VARCHAR(50) NULL;
```

After migrating, require a re-run of `scryfall:sync-bulk` to populate the new columns from the bulk JSON.

Update `BulkSyncService::applyBulkCardData()`:
- Add `commander_game_changer` → `(bool) ($c['game_changer'] ?? false)`
- Add `partner_scope` → derived from `keywords` + `oracle_text` (logic below)

Update `ScryfallCard` model:
- `$fillable` += `commander_game_changer`, `partner_scope`
- `$casts` += `'commander_game_changer' => 'boolean'`

**`derivePartnerScope` helper** (on BulkSyncService):

```php
private function derivePartnerScope(array $keywords, ?string $oracleText): ?string
{
    if (! in_array('Partner', $keywords, true)) {
        return null;
    }
    if (preg_match('/Partner[\x{2014}-]([A-Za-z ]+?)\s*\(/u', $oracleText ?? '', $m)) {
        return Str::snake(trim($m[1])); // "Friends forever" → "friends_forever"
    }
    return 'plain';
}
```

### 1.2 Create `scryfall_cards_raw`

```
CREATE TABLE scryfall_cards_raw (
  id           BIGINT PK auto_increment,
  scryfall_id  VARCHAR(36) UNIQUE NOT NULL,
  all_parts    JSON NULL,
  created_at, updated_at,
  FOREIGN KEY (scryfall_id) REFERENCES scryfall_cards(scryfall_id) ON DELETE CASCADE
);
```

Populated by `BulkSyncService::syncBulkCards()` — extract `all_parts` per card and upsert into this table alongside the main card upsert.

New `ScryfallCardRaw` model with `$fillable = ['scryfall_id', 'all_parts']` and `$casts = ['all_parts' => 'array']`.

### 1.3 Remove `user_cards`

**Pre-flight integrity check** (inside the `up()` method): assert every `scryfall_id` in `collection_entries` and `deck_entries` also exists in `scryfall_cards`. If not, abort with a clear error telling the operator to run `scryfall:sync-bulk` first.

Then:
- Drop FK `collection_entries.scryfall_id → user_cards.scryfall_id`, re-add → `scryfall_cards.scryfall_id` (nullOnDelete stays as-is)
- Same for `deck_entries.scryfall_id`
- Drop `user_cards` table

Update:
- `CollectionEntry::card()` — target `ScryfallCard`
- `DeckEntry::card()` — target `ScryfallCard`
- `CollectionController` sort mappings (currently joins `user_cards`) → join `scryfall_cards`
- `CardController::featured()` — query `scryfall_cards` instead
- `Location::refreshSetCodes()` — join `scryfall_cards` instead
- `ManaBoxImportService` — drop the `UserCard::updateOrCreate` step entirely; collection_entries can insert directly (scryfall_cards will have the card after the next bulk sync, and lazy-sync-on-read is already handled by `FetchCardTextData`)
- `FetchCardTextData` / `CardSyncService` — if these wrote to user_cards, rewrite to either write to scryfall_cards (if we own the record) or no-op (if bulk sync covers it)

Delete `app/Models/UserCard.php`.

### 1.4 Modify `decks` table

```
ALTER decks
  ADD commander_1_scryfall_id VARCHAR(36) NULL,
  ADD commander_2_scryfall_id VARCHAR(36) NULL,
  ADD color_identity          VARCHAR(5)  NULL,
  ADD group_id                BIGINT      NULL,
  ADD sort_order              INT         NOT NULL DEFAULT 0,
  ADD FOREIGN KEY (commander_1_scryfall_id) REFERENCES scryfall_cards(scryfall_id) ON DELETE SET NULL,
  ADD FOREIGN KEY (commander_2_scryfall_id) REFERENCES scryfall_cards(scryfall_id) ON DELETE SET NULL,
  ADD FOREIGN KEY (group_id) REFERENCES location_groups(id) ON DELETE SET NULL,
  ADD INDEX (user_id, sort_order);
```

**Format conversion:** `format` goes from `VARCHAR NULL` → enum. Per user decision, any existing row whose `format` is not in {commander, oathbreaker, pauper, standard, modern} gets deleted during migration (user is the only current user with zero decks; safe).

```
DELETE FROM decks WHERE format NOT IN ('commander', 'oathbreaker', 'pauper', 'standard', 'modern');
ALTER decks MODIFY format ENUM('commander', 'oathbreaker', 'pauper', 'standard', 'modern') NOT NULL;
```

### 1.5 Modify `deck_entries` table

```
ALTER deck_entries
  ADD zone                    ENUM('main','side','maybe') NOT NULL DEFAULT 'main',
  ADD category                VARCHAR(100) NULL,
  ADD is_commander            BOOLEAN NOT NULL DEFAULT FALSE,
  ADD is_signature_spell      BOOLEAN NOT NULL DEFAULT FALSE,
  ADD signature_for_entry_id  BIGINT NULL,
  ADD FOREIGN KEY (signature_for_entry_id) REFERENCES deck_entries(id) ON DELETE SET NULL;
```

**Data migration for zone:**
```
UPDATE deck_entries SET zone = 'side' WHERE is_sideboard = TRUE;
UPDATE deck_entries SET zone = 'main' WHERE is_sideboard = FALSE;
ALTER deck_entries DROP COLUMN is_sideboard;
```

**Wanted conversion (bool → enum nullable):**

Can't MODIFY a boolean column to a varchar enum safely in one step under MySQL 8. Do it in three:
```
ALTER deck_entries ADD wanted_new ENUM('main','side','maybe') NULL;
UPDATE deck_entries SET wanted_new = 'main' WHERE wanted = TRUE;
-- wanted=FALSE rows already have wanted_new=NULL (default)
ALTER deck_entries DROP COLUMN wanted;
ALTER deck_entries CHANGE wanted_new wanted ENUM('main','side','maybe') NULL;
```

`needs_review` column is untouched.

### 1.6 Create `deck_ignored_illegalities`

```
CREATE TABLE deck_ignored_illegalities (
  id BIGINT PK,
  deck_id BIGINT NOT NULL,
  illegality_type ENUM(
    'banned_card', 'color_identity_violation', 'duplicate_card',
    'invalid_partner', 'invalid_commander', 'deck_size',
    'too_many_cards', 'not_legal_in_format',
    'orphan_signature_spell', 'missing_signature_spell'
  ) NOT NULL,
  scryfall_id_1 VARCHAR(36) NULL,
  scryfall_id_2 VARCHAR(36) NULL,
  oracle_id     VARCHAR(36) NULL,
  expected_count INT UNSIGNED NULL,
  created_at, updated_at,
  FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
  UNIQUE (deck_id, illegality_type, scryfall_id_1, scryfall_id_2, oracle_id)
);
```

(Added `orphan_signature_spell` and `missing_signature_spell` to the enum to cover the oathbreaker checks.)

---

## Part 2 — Models

### `Deck`
- `$fillable`: user_id, name, format, description, is_archived, commander_1_scryfall_id, commander_2_scryfall_id, color_identity, group_id, sort_order
- `$casts`: is_archived => bool
- Relations:
  - `user()` → User
  - `entries()` → hasMany DeckEntry
  - `ignoredIllegalities()` → hasMany DeckIgnoredIllegality
  - `commander1()` → belongsTo ScryfallCard, 'commander_1_scryfall_id', 'scryfall_id'
  - `commander2()` → belongsTo ScryfallCard, 'commander_2_scryfall_id', 'scryfall_id'
  - `group()` → belongsTo LocationGroup

### `DeckEntry`
- `$fillable`: deck_id, scryfall_id, quantity, zone, category, is_commander, is_signature_spell, signature_for_entry_id, wanted, physical_copy_id, needs_review
- `$casts`: quantity => int, is_commander => bool, is_signature_spell => bool, needs_review => bool
- Relations:
  - `deck()` → Deck
  - `card()` → belongsTo ScryfallCard, 'scryfall_id', 'scryfall_id'  ← target changed from UserCard
  - `physicalCopy()` → belongsTo CollectionEntry, 'physical_copy_id'
  - `signatureFor()` → belongsTo DeckEntry, 'signature_for_entry_id'
  - `signatureSpells()` → hasMany DeckEntry, 'signature_for_entry_id'

### `DeckIgnoredIllegality` (new)
- `$fillable`: deck_id, illegality_type, scryfall_id_1, scryfall_id_2, oracle_id, expected_count
- Relations: `deck()` → Deck

### `ScryfallCardRaw` (new)
- `$fillable`: scryfall_id, all_parts
- `$casts`: all_parts => array
- Relation: `card()` → belongsTo ScryfallCard, 'scryfall_id', 'scryfall_id'

### `ScryfallCard` (updated)
- Add to `$fillable`: commander_game_changer, partner_scope
- Add to `$casts`: commander_game_changer => bool
- Add relation: `raw()` → hasOne ScryfallCardRaw, 'scryfall_id', 'scryfall_id'

---

## Part 3 — DeckLegalityService

`app/Services/DeckLegalityService.php`. Stateless — computes fresh each call. Controller diffs against `deck_ignored_illegalities`.

### Entry point

```php
public function check(Deck $deck): array
```

Returns array of associative arrays:
```
['type', 'scryfall_id_1', 'scryfall_id_2', 'oracle_id',
 'expected_count', 'message', 'card_name']
```

Eager-load inside the service (don't rely on caller): `entries.card`, `commander1`, `commander2`.

### Checks (in order)

**A. Deck size**

| Format                 | Main (incl. commanders/oathbreaker+signature) | Side    |
|------------------------|-----------------------------------------------|---------|
| commander              | 100                                           | any     |
| oathbreaker            | 60                                            | any     |
| standard/modern/pauper | 60                                            | 0 or 15 |

Failure → `deck_size` with `expected_count`.

**B. Format legality per card**

For each entry in zones main/side, read `scryfall_cards.legalities[format]`. Any value other than `'legal'` → `not_legal_in_format` with `scryfall_id_1`.

**C. Duplicate cards (oracle_id bucketing)**

Group main+side entries by `oracle_id`. Max copies:
- Commander / Oathbreaker: 1
- Standard / Modern / Pauper: 4

Exemptions (any of these lifts the cap):
- `hasSubtype($card, 'Basic')` AND `str_contains($type_line, 'Land')` — any number of basics
- `oracle_text` contains the literal string `A deck can have any number of cards named` — any number (Rat Colony, Relentless Rats, Shadowborn Apostle, Persistent Petitioners, Dragon's Approach, Templar Knight, etc.)
- Named exceptions with hard caps:
  - `Nazgûl` → up to 9
  - `Seven Dwarves` → up to 7

Over-cap → `duplicate_card` with `oracle_id`.

**D. Color identity (Commander + Oathbreaker only)**

Deck identity = union of `color_identity` JSON arrays from commander(s), canonicalized with `BulkSyncService::canonicaliseColors` (reused). Stored on `decks.color_identity` as canonical string (`"WUB"`).

For each non-land card in main zone (non-signature-spell), check that `card.color_identity` ⊆ `deck.color_identity`. Lands are exempt. Failure → `color_identity_violation` with `scryfall_id_1`.

**E. Commander validity (Commander format)**

- At least one commander set on `decks.commander_1_scryfall_id` → else `invalid_commander` (no scryfall_id_1).
- Each commander must be: a Legendary Creature, OR `oracle_text` contains `can be your commander`. Failure → `invalid_commander` with `scryfall_id_1`.
- If both commanders set, pair check (see below). Failure → `invalid_partner` with both scryfall_ids.

Pair check:
```php
function arePartners(ScryfallCard $c1, ScryfallCard $c2): bool {
    // Symmetric: same partner_scope bucket
    if ($c1->partner_scope && $c2->partner_scope &&
        $c1->partner_scope === $c2->partner_scope) return true;

    // Asymmetric: Doctor's companion ↔ Doctor subtype
    if (in_array("Doctor's companion", $c1->keywords, true) && $this->hasSubtype($c2, 'Doctor')) return true;
    if (in_array("Doctor's companion", $c2->keywords, true) && $this->hasSubtype($c1, 'Doctor')) return true;

    // Asymmetric: Choose a background ↔ Background subtype
    if (in_array('Choose a background', $c1->keywords, true) && $this->hasSubtype($c2, 'Background')) return true;
    if (in_array('Choose a background', $c2->keywords, true) && $this->hasSubtype($c1, 'Background')) return true;

    return false;
}
```

`hasSubtype(card, needle)` splits `type_line` on em-dash (U+2014) and tokenizes by whitespace, exact-match against `needle`.

**F. Oathbreaker validity**

- Oathbreaker(s) live on `decks.commander_1_scryfall_id` / `commander_2_scryfall_id`. They must be Legendary Planeswalkers. Pair check reuses the partner logic (two-planeswalker decks are allowed if both have Partner etc.).
- Each oathbreaker needs exactly one signature spell — a `deck_entries` row with `is_signature_spell = true` and `signature_for_entry_id` pointing at the oathbreaker's entry.
- Signature spell must be: Instant or Sorcery (`hasType(card, 'Instant') || hasType(card, 'Sorcery')`), AND `color_identity ⊆ oathbreaker.color_identity`.
- Orphaned signature spells (`signature_for_entry_id` null or pointing at a non-commander entry) → `orphan_signature_spell`.
- Oathbreaker without a signature spell → `missing_signature_spell`.

---

## Part 4 — API Endpoints

All under `middleware('auth:api')`. Every endpoint scopes by `auth()->id()`.

### DeckController

- `GET /api/decks` — index. Returns array of decks with `commander1`/`commander2` (id, name, image_small, color_identity, commander_game_changer), `entry_count` (main zone), `illegality_count` (active, non-ignored).
- `POST /api/decks` — store. Validates name/format/commanders; recomputes `color_identity` if commanders set.
- `GET /api/decks/{deck}` — show. Full deck + commanders + entries grouped by zone + current illegalities (with `ignored` flag).
- `PUT /api/decks/{deck}` — update metadata. On commander change, recompute `color_identity`. Manages `is_commander` flag on matching deck_entries.
- `DELETE /api/decks/{deck}` — cascade.

### DeckEntryController

- `GET /api/decks/{deck}/entries` — filter by zone/category, sort by name/cmc/color/rarity/category. Each entry joined with `scryfall_card` (full), `oracle_tags`, `owned_copies`, `available_copies`. **Aggregates computed once via grouped query, hash-joined in PHP** — no N+1.
- `POST /api/decks/{deck}/entries` — add card. Auto-category:
  1. If user-provided `category` is null, query `card_oracle_tags` for this oracle_id
  2. First tag in the `config('scryfall.oracle_tags')` allowlist wins (order-preserving)
  3. Fallback: type-line priority `Battle → Planeswalker → Creature → Land → Instant/Sorcery → Artifact → Enchantment`
  
  If `is_commander: true`: fill the first empty commander slot on the deck, recompute `color_identity`. Reject if both slots full.
- `PATCH /api/decks/{deck}/entries/{entry}` — update zone/quantity/category/physical_copy_id/is_signature_spell/signature_for_entry_id.
- `DELETE /api/decks/{deck}/entries/{entry}` — delete. If commander, clear the deck's commander slot + recompute color_identity. Signature spells of a deleted oathbreaker become orphans (flagged by legality engine, not auto-deleted).

### DeckLegalityController

- `GET /api/decks/{deck}/illegalities` — run service, diff against ignored list, return all with `ignored: bool`.
- `POST /api/decks/{deck}/illegalities/ignore` — body: `{illegality_type, scryfall_id_1?, scryfall_id_2?, oracle_id?, expected_count?}`. `firstOrCreate` on unique constraint. 201.
- `POST /api/decks/{deck}/illegalities/unignore` — body: same fields. Delete matching row. 204.

### Routes

Replace existing `Route::apiResource('decks', DeckController::class)` at api.php:41 with explicit lines:

```php
Route::get   ('decks',                         [DeckController::class, 'index']);
Route::post  ('decks',                         [DeckController::class, 'store']);
Route::get   ('decks/{deck}',                  [DeckController::class, 'show']);
Route::put   ('decks/{deck}',                  [DeckController::class, 'update']);
Route::delete('decks/{deck}',                  [DeckController::class, 'destroy']);

Route::get   ('decks/{deck}/entries',               [DeckEntryController::class, 'index']);
Route::post  ('decks/{deck}/entries',               [DeckEntryController::class, 'store']);
Route::patch ('decks/{deck}/entries/{entry}',       [DeckEntryController::class, 'update']);
Route::delete('decks/{deck}/entries/{entry}',       [DeckEntryController::class, 'destroy']);

Route::get ('decks/{deck}/illegalities',          [DeckLegalityController::class, 'index']);
Route::post('decks/{deck}/illegalities/ignore',   [DeckLegalityController::class, 'ignore']);
Route::post('decks/{deck}/illegalities/unignore', [DeckLegalityController::class, 'unignore']);
```

---

## Part 5 — Sidebar integration

Modify `LocationGroupController::index()` (not LocationController — that returns flat list). Current shape: `{items: [...], total_count}`. New shape:

```json
{
  "items": ["..."],
  "total_count": 12345,
  "decks": [
    {
      "id": 1,
      "name": "Lifegain Aerith",
      "format": "commander",
      "color_identity": "WG",
      "entry_count": 100,
      "illegality_count": 0,
      "group_id": null,
      "sort_order": 0,
      "commander1": { "name": "...", "image_small": "..." }
    }
  ]
}
```

Decks reuse `location_groups` via the new `decks.group_id` column. Drag-drop endpoint for decks can piggyback on `LocationGroupController@reorder` if we extend its payload to accept deck items too — but that's the frontend's concern for DB-2.

---

## Part 6 — CollectionController update (zone-aware wanted)

`wantedByDecksMap()` currently returns `[scryfall_id => [deck_id, ...]]`. Change to:

```
[scryfall_id => [{deck_id, deck_name, zone}]]
```

Query: `SELECT deck_entries.*, decks.name AS deck_name FROM deck_entries JOIN decks ON ... WHERE wanted IS NOT NULL AND physical_copy_id IS NULL AND decks.user_id = ?`. Response field `wanted_by_decks` becomes the richer shape. Frontend (DB-2) consumes it.

---

## Part 7 — Tests

New files under `api/tests/`:

- `Feature/DeckControllerTest.php` — CRUD, auth, user-scoping, commander slot management, cascade on delete.
- `Feature/DeckEntryControllerTest.php` — add/remove/update, auto-category resolution (DataProvider: tags → expected category, type_line → expected fallback), signature spell attachment, owned/available copies aggregation.
- `Feature/DeckLegalityControllerTest.php` — illegality listing, ignore/unignore, ignored flag diffing.
- `Unit/DeckLegalityServiceTest.php` — **highest coverage target**, DataProvider-driven. One provider per check type:
  - `deckSizeProvider()` — [(format, main_count, side_count, expected_illegalities)]
  - `formatLegalityProvider()` — [(format, card_legalities, expected)]
  - `duplicateProvider()` — [(format, cards_by_oracle, expected)]
  - `colorIdentityProvider()` — [(deck_identity, card_identity, is_land, expected)]
  - `commanderValidityProvider()` — [(card_type_line, oracle_text, expected_valid)]
  - `partnerPairProvider()` — [(c1_keywords, c1_scope, c1_type_line, c2_..., expected_pair)]
  - `oathbreakerProvider()` — [(entries_config, expected_illegalities)]
- `Unit/MtgVectorsServiceTest.php` — (existing service; light touch if we touch anything tangential)
- `Unit/BulkSyncServiceTest.php` — `derivePartnerScope` DataProvider, seeded with these verified cases:
  - `(['Partner'], 'Partner (You can have two commanders if both have partner.)', 'plain')` — Tana
  - `(['Partner with', 'Partner'], 'Partner with Toothy, Imaginary Friend (When this creature enters…)', 'plain')` — Pir, proves the comma in the named partner doesn't get captured
  - `(['Partner'], "Partner\u2014Friends forever (You can have two commanders if both have this ability.)", 'friends_forever')` — Bjorna
  - `(['Partner'], "Partner\u2014Survivors (You can have two commanders if both have this ability.)", 'survivors')` — Abby
  - `(['Partner'], "Partner\u2014Character select (You can have two commanders if both have this ability.)", 'character_select')` — Leonardo
  - `(['Flying'], 'any oracle text', null)` — no Partner keyword → null
  - `(['Partner'], null, 'plain')` — keyword present but no oracle (defensive)

DataProvider patterns keep each provider <30 rows — one row per distinct behavior. Tests target readable assertion chains, not exhaustive permutation. Target: every public method touched has ≥1 test; every branch in the legality service has ≥1 test row.

---

## Part 8 — Verification (manual)

Sanity after `php artisan migrate` + `scryfall:sync-bulk` (needed to populate new columns):

```bash
# Create a commander deck
curl -sS -X POST http://localhost:8080/api/decks \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Test","format":"commander","commander_1_scryfall_id":"<uuid>"}' \
  | jq '{id, color_identity}'

# Verify fresh deck shows deck_size illegality
curl -sS http://localhost:8080/api/decks/1/illegalities \
  -H "Authorization: Bearer $TOKEN" | jq '.[] | {type, message}'

# Ignore and verify flipped
curl -sS -X POST http://localhost:8080/api/decks/1/illegalities/ignore \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"illegality_type":"deck_size","expected_count":100}'

curl -sS http://localhost:8080/api/decks/1/illegalities \
  -H "Authorization: Bearer $TOKEN" | jq '.[] | {type, ignored}'

# Deck appears in sidebar payload
curl -sS http://localhost:8080/api/location-groups \
  -H "Authorization: Bearer $TOKEN" | jq '.decks[0]'

# Auto-category assignment worked
curl -sS http://localhost:8080/api/decks/1/entries \
  -H "Authorization: Bearer $TOKEN" | jq '.[0] | {name: .scryfall_card.name, category}'
```

---

## Out of scope for DB-1

- Frontend components (DB-2)
- Import/export of deck lists (.dec / .mtgo / moxfield / archidekt)
- Playtester / sample hand / mulligan simulator
- Price tracking and snapshots
- Card search UI (Scryfall syntax parser) — may use ES eventually
- Subtypes JSON column on scryfall_cards — defer to card-search session

---

## Migration runbook (strict order, fully self-healing)

No manual pre-steps required. `php artisan migrate --force` handles everything.

Migrations in order:

1. `add_commander_game_changer_and_partner_scope_to_scryfall_cards`
2. `create_scryfall_cards_raw` — **triggers `scryfall:sync-bulk` if `scryfall_cards` already has rows**, populating the new columns from step 1 and the new raw table in one pass
3. `drop_user_cards_and_repoint_fks` — pre-flight counts FK orphans; if non-zero, runs `scryfall:sync-bulk` in-place and re-checks. Aborts only if orphans remain after the sync (points at Scryfall-deleted printings that need manual attention)
4. `extend_decks_for_deckbuilder`
5. `convert_decks_format_to_enum`
6. `extend_deck_entries_for_deckbuilder`
7. `convert_deck_entries_wanted_to_enum`
8. `create_deck_ignored_illegalities`

**Timing note:** on an existing environment with populated `scryfall_cards`, step 2 runs `sync-bulk` (typically 5-10 minutes) — deploys that pull this migration set will be slower than normal. Fresh installs skip the sync (empty tables have nothing to populate).

**Failure mode:** if `scryfall:sync-bulk` fails during step 2 or step 3, the migration throws with a clear message. The schema is in a mixed state (steps 1-2 may have applied); re-running `migrate --force` resumes from the first unapplied migration once the sync issue is resolved.
