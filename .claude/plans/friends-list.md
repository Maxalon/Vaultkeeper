# Friends List + Card-Matching + Centralized Notifications

## Context

Vaultkeeper tracks users' physical Magic card collections and their decks. A common user friction is: while building a deck, a card is marked `wanted`, and the user has no idea whether a friend has a spare unused copy they could borrow or trade for. Today there is no social layer — users are siloed.

This project adds three things that ship together because they reinforce each other:

1. **A friend graph** so users can connect with people they already know.
2. **A wanted-card matcher** that surfaces, per `wanted` deck entry, which friends have an *available* (non-deck-location) copy.
3. **A centralized notifications table** that replaces the ephemeral toast-only model. This is needed for friend-request inbox semantics, and it doubles as a long-requested "recent activity log" so users no longer lose toast actions (e.g. bulk-import undo) the moment the toast disappears.

Privacy is a first-class constraint: no public profiles, no email/contact import (ever), no email/push notifications (ever), in-app only.

---

## Locked Product Decisions

| # | Decision |
|---|----------|
| 1 | **Friend model:** mutual, request-based. A → B sends request, B accepts. Symmetric thereafter. |
| 2 | **No public mode.** Ever. Collections and decks are visible only to accepted friends. No "public profile" toggle will exist. |
| 3 | **"Available" definition:** a `CollectionEntry` whose `location.role = 'user'` (i.e. NOT a `ROLE_DECK` shadow location). This naturally excludes assembled cards because `DeckAssemblyService` moves them into the deck's shadow location. |
| 4 | **Discovery:** username search only. Email is private at all times. No email/contact import — ever. This constraint also applies to the future mobile app (not yet built): it will never request contact permissions. |
| 5 | **No blocking feature.** Unfriending is sufficient. Spam is prevented by request deduplication: while A's request to B is `pending`, A cannot send another request to B. (B never has to accept; the request can sit forever.) |
| 6 | **Notifications: in-app only.** No email, no push, no SMS — ever. |
| 7 | **Centralized `notifications` table.** Reusable for all event types. Existing toast actions (e.g. bulk-import) are *also* persisted here so users get a "recent activity log" with the same actions the toast offered, until the underlying state changes and invalidates them. |

---

## Data Model

### `friendships`
```
id                bigint pk
user_a_id         fk users  -- always least(requester, addressee)
user_b_id         fk users  -- always greatest(requester, addressee)
requester_id      fk users  -- who initiated
status            enum('pending','accepted','declined')
                  -- no 'blocked' (per decision 5); 'declined' is terminal
created_at, responded_at, updated_at
unique(user_a_id, user_b_id)
index(user_a_id, status), index(user_b_id, status)
```
Storing the canonical `(least, greatest)` ordering enforces the "one row per pair" invariant at the DB level. Request dedup (decision 5) becomes a single uniqueness check.

### `user_privacy_settings`
```
user_id                  fk users pk
collection_visibility    enum('friends','private')  default 'friends'
decks_visibility         enum('friends','private')  default 'friends'
discoverable             boolean  default true       -- if false, username search excludes them
```
No `'public'` value in the enums — encodes decision 2 at the schema level.

### `notifications` (centralized, reusable)
```
id           bigint pk
user_id      fk users  -- recipient
type         string    -- e.g. 'friend.request_received', 'friend.request_accepted',
                       --      'collection.bulk_import_completed', 'deck.card_marked_for_review'
payload      json      -- type-specific (e.g. {requester_id, requester_username})
actions      json      -- [{key, label, kind, endpoint, method, body, invalidates_on}]
read_at      timestamp nullable
created_at, updated_at
index(user_id, read_at), index(user_id, created_at)
```

#### The `actions` contract (this is the part that needs care)

Each action is **declarative**, not a closure, because it has to survive a page refresh. Shape:

```json
{
  "key": "sold_or_discarded",
  "label": "Sold / discarded",
  "kind": "default" | "danger",
  "endpoint": "/collection/reviews/resolve",
  "method": "POST",
  "body": { "collection_entry_id": 42, "discard": true },
  "invalidates_on": [
    { "model": "CollectionEntry", "id": 42, "field": "location_id" },
    { "model": "CollectionEntry", "id": 42, "deleted": true }
  ]
}
```

**Stale detection** uses `invalidates_on`. Implementation: a Laravel observer on each watched model writes a `model_mutations` row (or bumps a per-record counter) when listed fields change or the record is deleted. The notifications endpoint joins/checks this and returns `actions[].available: bool` on read. Frontend hides unavailable actions and shows "no longer available".

This is the minimum viable mechanism. Alternative considered: snapshot the version field of each watched record (`updated_at` + `id`) and compare on click — simpler but racier. Recommend the observer + mutation-log approach since `CollectionEntry` already uses `HasOptimisticVersion`; we can piggyback on the version column instead of a new table. **Decision for project lead:** confirm we extend `HasOptimisticVersion` rather than add a new mutation log.

---

## API Contract

All endpoints are JWT-auth, under `/api`, and follow existing Laravel REST conventions.

### Friend graph
- `POST   /friends/requests`           — `{ username }` → 201 (creates pending). 409 if already pending/accepted/declined.
- `GET    /friends/requests`           — `?direction=incoming|outgoing` → list.
- `PATCH  /friends/requests/{id}`      — `{ action: 'accept'|'decline' }` (recipient only).
- `DELETE /friends/requests/{id}`      — cancel (requester only).
- `GET    /friends`                    — accepted friends list.
- `DELETE /friends/{user}`             — unfriend (symmetric tear-down).
- `GET    /users/search?q=`            — username prefix search; rate-limited 30/min; excludes `discoverable=false` users; excludes self and existing friends/pending.

### Friend visibility
- `GET    /users/{user}/collection`    — gated on accepted friendship + `collection_visibility='friends'`.
- `GET    /users/{user}/decks`         — gated on accepted friendship + `decks_visibility='friends'`.
- `GET    /privacy-settings`, `PATCH /privacy-settings`

### Wanted matcher (the showcase)
- `GET    /decks/{deck}/wanted-matches`
    - Returns: `[{ scryfall_card_id, card_name, wanted_quantity, friends: [{ user_id, username, available_copies: [{ collection_entry_id, condition, foil, location_name }] }] }]`
    - Single SQL: `deck_entries WHERE wanted` ⨝ accepted friends ⨝ `collection_entries` of those friends WHERE `scryfall_card_id` matches AND `locations.role = 'user'` AND friend's `collection_visibility = 'friends'`.
    - **Required new index:** `collection_entries(scryfall_card_id, user_id) WHERE location_id IN (user-role locations)` — or simpler, composite `(scryfall_card_id, user_id, location_id)`. Confirm with `EXPLAIN` against seeded data before merge.

### Notifications
- `GET    /notifications`              — `?unread=1` filter; paginated; includes `actions[].available`.
- `POST   /notifications/{id}/read`    — mark read.
- `POST   /notifications/read-all`
- `POST   /notifications/{id}/actions/{key}` — server-side gateway: re-checks `invalidates_on`, then proxies to the underlying endpoint. (Don't let the client call the underlying endpoint directly — it lets us audit and gives a single place to enforce the staleness rule.)

---

## Workstreams (3 devs, ~3.5 weeks)

### Day 0 — Shared (everyone, half day)
Lock the API contract above as an OpenAPI doc (or Laravel route file with PHPDoc) on the branch. Mock JSON fixtures committed under `web/src/__mocks__/friends/` so frontend devs aren't blocked.

### Stream A — Backend & Data (Dev 1)

**A1. Migrations**
- `friendships`, `user_privacy_settings`, `notifications`
- Seeders for QA / staging
- Add the matcher index on `collection_entries`

**A2. Models & policies**
- `Friendship`, `UserPrivacySetting`, `Notification` Eloquent models
- Scopes: `Friendship::accepted()`, `pendingFor($user)`, `betweenUsers($a, $b)`
- New `FriendshipPolicy`
- Update `CollectionEntryPolicy`, `DeckPolicy` to allow read for accepted friends honoring visibility

**A3. Friend endpoints** (see contract above) + request dedup logic + rate limit on search (30/min)

**A4. Matcher endpoint** with `EXPLAIN`-verified query and feature test seeded with two users + 50 cards

**A5. Notification system**
- Create `NotificationService` with `notify($user, $type, $payload, $actions)`
- Wire `friend.request_received` and `friend.request_accepted` events
- **Retrofit existing toast-firing code paths to also persist notifications** — at minimum:
    - `BulkImportCompleted` (currently no undo; project lead wanted it)
    - The deck-removal "Sold / discarded" path in `web/src/stores/deck.js` → its server side
- Implement the action gateway (`POST /notifications/{id}/actions/{key}`)
- Implement staleness detection by extending the existing `HasOptimisticVersion` trait (see decision in Data Model section)

### Stream B — Frontend Social UI (Dev 2)

Can start day 1 against the mocked endpoints.

**B1.** Pinia `friends` store + Pinia `notifications` store (replaces parts of `useToast`)

**B2.** New `/friends` route — tabs: Friends / Requests (incoming + outgoing)

**B3.** `AddFriendModal` with debounced username search

**B4.** Notification bell in app header (unread count, dropdown list, "see all" page)
- Renders declarative actions from the notification payload
- Hides actions where `available: false`, shows "no longer available" tooltip
- Clicking an action POSTs to the gateway

**B5.** Privacy settings panel in `SettingsView.vue`

**B6.** Read-only collection view for friends — extend `CollectionView.vue` with `readOnly` + `userId` props rather than duplicating

**B7.** Migrate `useToast` to dual-fire: ephemeral toast + persisted notification. Toast-only callers become a thin wrapper that pushes to both.

### Stream C — Matcher UX (Dev 3)

**C1.** In `DeckView.vue`, on each `wanted` row: small avatar stack of friends who have a copy

**C2.** Click → side panel listing friends + condition/foil/storage location + a "copy username to clipboard" action (no in-app messaging in v1; out-of-band coordination)

**C3.** "Wanted matches" summary tab on the deck view: aggregated, sortable list

**C4.** Empty/loading/error states: 0 friends, 0 visible friends, 0 matches, friend revoked visibility mid-session

**C5.** No reservation system in v1 — if friend has 1 copy and 2 users want it, both see it. Document this clearly in tooltip.

### Cross-cutting
- Policy tests (Stream A writes these *first*, before endpoints — privacy regression is the worst-case bug)
- Friendship state-machine unit tests (request-self, double-accept, request-existing-friend, etc.)
- E2E: register two users → A friends B → B accepts → A's deck shows B's available copy
- Migration rollback verification (security audit flagged this as a gap)

---

## Critical Files

### To create
- `api/database/migrations/<ts>_create_friendships_table.php`
- `api/database/migrations/<ts>_create_user_privacy_settings_table.php`
- `api/database/migrations/<ts>_create_notifications_table.php`
- `api/database/migrations/<ts>_add_collection_match_index.php`
- `api/app/Models/Friendship.php`, `UserPrivacySetting.php`, `Notification.php`
- `api/app/Http/Controllers/FriendController.php`, `FriendRequestController.php`, `NotificationController.php`, `PrivacySettingController.php`, `UserSearchController.php`
- `api/app/Services/NotificationService.php`
- `api/app/Policies/FriendshipPolicy.php`
- `web/src/views/FriendsView.vue`, `NotificationsView.vue`
- `web/src/stores/friends.js`, `notifications.js`
- `web/src/components/AddFriendModal.vue`, `NotificationBell.vue`, `WantedMatchPanel.vue`

### To modify
- `api/routes/api.php` — register new routes
- `api/app/Models/User.php` — `friends()`, `privacySettings()`, `notifications()` relations
- `api/app/Models/CollectionEntry.php` — extend `HasOptimisticVersion` integration for staleness
- `api/app/Policies/CollectionEntryPolicy.php`, `DeckPolicy.php` — friend-read rules
- `api/app/Services/DeckAssemblyService.php` — emit notification on assemble/unassemble that affects a friend-visible card (low priority, may defer)
- `api/app/Jobs/*` related to bulk import — emit `collection.bulk_import_completed` notification
- `web/src/composables/useToast.js` — add `persist: true` option that also writes to `notifications` store
- `web/src/stores/deck.js`, `bulkImport.js` — adopt persisted notifications for actions
- `web/src/views/DeckView.vue` — wanted-match indicators (Stream C)
- `web/src/views/SettingsView.vue` — privacy section
- `web/src/router/index.js` — `/friends`, `/notifications` routes
- `web/src/components/AppHeader.vue` (or equivalent) — notification bell

### Reused (do NOT duplicate)
- `Location` model + `ensureDeckLocation` in `DeckAssemblyService` (line 215) — the source of truth for "is this card in a deck"
- `HasOptimisticVersion` trait — extend rather than replace for staleness detection
- `CollectionView.vue` — make it `readOnly`-aware rather than fork it
- Existing JWT auth middleware, rate-limit configuration, `auth:api`

---

## Verification

**Backend**
1. `php artisan migrate:fresh --seed` succeeds.
2. `php artisan test --filter=Friendship` — state-machine tests pass.
3. `php artisan test --filter=Policy` — privacy tests: a non-friend cannot read collection/decks; a friend with `visibility=private` cannot either.
4. `EXPLAIN ANALYZE` on `/decks/{deck}/wanted-matches` query stays under 50ms with seeded data of 50 friends × 1000 cards.
5. Notification staleness: create a notification with an action, mutate the underlying record, fetch `/notifications` — `actions[].available = false`.
6. Request dedup: A → B request, A retries → 409.

**Frontend**
1. Two-user E2E: register A and B → A searches B's username → sends request → B sees bell badge increment → B accepts → A sees acceptance notification → A opens a deck with a card B owns → match appears in `WantedMatchPanel`.
2. Refresh mid-flow: notification list survives reload, actions still work, action becomes unavailable after relevant mutation.
3. Privacy: B sets `collection_visibility=private` → A's matcher view drops B from results within one cache cycle.
4. Toast → notification continuity: complete a bulk import → toast appears → toast dismisses → action remains available in notification list → mutate one of the imported entries → action shows as unavailable.

**Manual QA pass**
- Try every privacy regression I can think of (revoke visibility, unfriend, decline-then-resend, decline-then-receive-from-them, etc.)

---

## Risks

1. **Privacy regression** — single highest-risk class of bug. Mitigation: policy tests written before endpoints; dedicated QA pass for visibility scenarios.
2. **Matcher query perf at scale** — addressed by the new composite index, verified with `EXPLAIN`. Fallback: denormalized `available_copy_count(user_id, scryfall_card_id)` updated by `CollectionEntry` observer.
3. **Notification action staleness false-negatives** (action shown as available but fails on click) — gateway re-checks server-side at execution, so worst case is a 4xx the UI handles gracefully, not data corruption.
4. **Friendship state-machine edge cases** — exhaustive unit tests; canonical `(least, greatest)` ordering in DB removes the "two rows per pair" failure mode entirely.
5. **Username harassment vectors** — partially mitigated by request dedup + `discoverable=false` opt-out. If this proves insufficient post-launch we can add blocking; not in v1.

---

## Open Questions for Project Lead

1. **Staleness mechanism**: extend `HasOptimisticVersion` (recommended) vs new `model_mutations` log? Affects A5 scope by ~1 day.
2. **Username change policy**: do we allow it? If yes, friend rows need to handle it (we store `user_id`, not username, so it's fine — but search index needs invalidation). If we already disallow username changes, no work.
3. **Friend's deck visibility on the matcher** — when surfacing a friend's "available" card, do we show *which* of their decks it's NOT in (i.e. confirm it's not earmarked)? Recommended: no, "available" already means non-deck-location, so this is implicit.
4. **Notification retention**: cap at N per user, or time-based purge? Recommend keep-last-200 per user, hard cap, oldest-first eviction.
