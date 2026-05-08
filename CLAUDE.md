# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repo layout

```
api/    Laravel 13 / PHP 8.4 backend (JWT auth, MySQL, Horizon queues)
web/    Vue 3 + Vite SPA (Pinia stores, Vue Router)
app/    Kotlin/Compose Android client (placeholder)
docker/ Dockerfiles for dev + prod images and the dev nginx config
e2e/    Playwright browser suite that drives the running dev stack
docs/   DEPLOYMENT.md (operators) and local-dev.md (Podman quirks)
```

The dev stack is Compose-shaped but the maintainer runs **rootless Podman**
on Fedora. `docker compose` calls go through the user-level Podman socket;
exec into containers with `podman exec` directly when invoking commands
during a session. See `docs/local-dev.md` for the boot dance.

## Common commands

All commands assume the dev stack is up (`docker compose up -d`). Runtime
containers: `vaultkeeper_api` (php-fpm), `vaultkeeper_worker` (queue),
`vaultkeeper_web` (vite), `vaultkeeper_db` (MySQL 8.4), `vaultkeeper_nginx`
(:8080 → api+web). The DB user is `vaultkeeper`; the test DB
`vaultkeeper_test` must exist and be granted to that user before running
the API suite for the first time.

### Backend (PHP / Laravel)

```bash
# Run the full Feature + Unit suite (CI parity)
podman exec vaultkeeper_api php artisan test --no-coverage

# Run a single test class or filter
podman exec vaultkeeper_api php artisan test --filter DeckEntryControllerTest --no-coverage
podman exec vaultkeeper_api php artisan test --filter test_patch_scryfall_id_swaps_printing --no-coverage

# Migrations / DB
podman exec vaultkeeper_api php artisan migrate
podman exec vaultkeeper_api php artisan migrate:fresh --seed --force   # destructive — wipes vaultkeeper DB

# Lint (Pint)
podman exec vaultkeeper_api ./vendor/bin/pint
```

### Frontend (Vue / Vite)

```bash
podman exec vaultkeeper_web npx vitest run                       # full unit suite
podman exec vaultkeeper_web npx vitest run path/to/file.spec.js  # single spec
podman exec vaultkeeper_web npm run build                        # prod build
```

### E2E (Playwright)

E2E hits the real stack at `http://localhost:8080` against a seeded user
(`testuser` / `password`). Run from `web/`: `npm run e2e` (headless),
`npm run e2e:headed`, `npm run e2e:ui`, `npm run e2e:reset` (rewipe seed).

## Architecture cross-cuts

These are the bits that take reading several files to internalise. Lean on
them before extending.

### Deck state lives in two slices

`web/src/stores/deck.js` keeps `deck.deck` (deck row + nested
`commander1`/`commander2`/`companion` ScryfallCard objects) and
`deck.entries` (DeckEntry rows) as **separate** reactive state. Loaders
(`loadDeck`, `loadEntries`) hit different endpoints and only one of them
runs after most mutations. Mutations whose backend effects span both
slices (anything that triggers `syncCommanderEntries` or rewrites
`commander_*_scryfall_id`) must explicitly refresh entries — the deck
detail response does not include them.

### Commander slots are mirrored, not single-source

`decks.commander_{1,2}_scryfall_id` is the canonical pointer for a
commander slot. `DeckEntry.is_commander` is a derived flag kept in sync by
`DeckController::syncCommanderEntries` whenever those columns change.
Keep these in lockstep — code that touches one without the other (e.g.
changing the commander's printing without mirroring the new
`scryfall_id` into the deck slot) leaves the deck's `commander1` relation
pointing at the wrong card. `DeckEntryController::update` and
`reconcileCommandersAfterRemoval` are the canonical sync points.

### Physical-copy binding semantics

Ownership is **implicit**, not a deck-level flag: a deck is "assembled"
iff at least one of its entries has `physical_copy_id` set. Each bound
`DeckEntry` references a `CollectionEntry` (a real owned card with
condition/foil/location). `DeckEntryObserver` handles the side effects:
unbinding (or deleting) an entry whose copy lives in this deck's
auto-managed deck-Location routes the freed CE to the review queue with
reason `no_location`. The "skipPendingQueueOnce" one-shot flag on the
entry suppresses this — `DeckEntryActionService` uses it to express
"sold/discarded" intents that already account for the copy.

`DeckObserver` auto-creates and renames the deck-Location, and FK-cascade
removes it on deck delete. Don't introduce a parallel `is_assembled`
column.

### Wanted vs bound rows (and "split pairs")

An unbound entry with `wanted = zone` is on the wishlist. The grow path
(`+1` / catalog-drag) goes through `growWanted`, which never touches a
bound sibling — bound quantity only changes via the explicit "I bought
it" flow. `mergedEntriesByZone` in `deck.js` coalesces a (bound,
wanted-only) pair for the same `(scryfall_id, zone)` into one display row
with combined quantity (`_split: true`). Commanders and signature spells
are never coalesced.

### Tab system

`web/src/stores/tabs.js` is a binary-tree split layout (leaf with tabs vs
horizontal/vertical split with two children) persisted in localStorage.
Some tab types (`deck`, `side`, `maybe`, `review`, `physical`,
`wanted-matches`) are flagged single-instance in `SINGLE_INSTANCE` —
opening one closes any duplicate.

### Right-rail arbitration in the deck view

`DeckView.vue` arbitrates the right-rail slot between
`CatalogDetailSidebar` and `DeckDetailSidebar`. Watchers ensure the two
can't both be open: setting `deck.activeEntryId` clears
`catalog.activeCardOracleId` and vice versa. Components that open one
side don't need to clear the other.

### Auth / throttling

JWT (tymon/jwt-auth). The authenticated route group runs
`throttle:120,1`; login/register/forgot/reset get a tighter `10,1`;
import endpoints (Archidekt bulk, CSV, text) get `5,1` because they
dispatch long-running queue jobs. Deck `*-show` traffic should not need
extra throttling.

## Deploy pipeline (read before editing CI/branches)

- `staging` branch — auto-build to GHCR via `.github/workflows/deploy.yml`,
  auto-deploys to the staging compose project on the prod host. Pushes
  to staging are live within minutes.
- `main` branch — same build pipeline, manual operator gate to deploy to
  prod (`docker-compose.prod.yml -p vaultkeeper_prod`).
- CI runs on PRs and on push to `main`/`staging`: `php artisan test`
  (Feature + Unit) and `npm run build`.

## Git workflow

**Verify the current branch before every commit or push.** Run
`git branch --show-current` (or `git status`) immediately before any
`git add` / `git commit` / `git push`, even if a feature branch was
created earlier in the same session.

Why: the user may switch branches between turns (via the IDE or
directly) without announcing it. The conversation's initial branch
state is a snapshot and goes stale. `staging` auto-deploys, so a
follow-up commit assumed to be on a feature branch can ship to staging
without a PR if the branch wasn't re-checked.
