# Vaultkeeper E2E (Playwright)

End-to-end suite that drives a real Chromium against the running dev stack
(`http://localhost:8080`). Distinct from `claude-sandbox/`, which mocks the
backend for component-level harness tests in cloud Claude sessions; this
suite hits the real Vue SPA + Laravel API + MySQL.

## Prerequisites

The dev stack must be **up and reachable on `http://localhost:8080`**, with
the seeded test user (`testuser` / `password`) present.

On Fedora / rootless Podman, this means once per boot:

```bash
systemctl --user enable --now podman.socket
export DOCKER_HOST=unix:///run/user/$UID/podman/podman.sock   # add to ~/.bashrc
```

Then from the repo root:

```bash
docker compose up -d
docker compose exec api php artisan migrate:fresh --seed --force
```

See `docs/local-dev.md` for the full backstory on the Podman quirks
(`docker-compose.override.yml`, `userns_mode: keep-id`, `runtime: runc`).

## Run the suite

All commands run from `web/`:

```bash
npm run e2e          # headless — fastest, just a pass/fail
npm run e2e:headed   # Chromium opens on your display, slowed to 300ms/step
npm run e2e:ui       # Playwright's UI runner (timeline, time-travel)
npm run e2e:reset    # wipe the DB and re-seed (when tests have left state behind)
```

The `setup` project runs `auth.setup.ts` once per invocation: it logs in via
the API, plants the JWT into `localStorage`, and saves the browser state to
`.auth/user.json`. Every other spec reuses that state and starts already
logged in.

## Watching / debugging

- `npm run e2e:headed` — live, real-time browser window.
- `npm run e2e:ui` — Playwright's UI mode; pick which test to run, scrub
  through DOM snapshots per step, time-travel back to any line.
- After a failed headless run: `npx playwright show-report` (or just open
  `playwright-report/index.html`) — full trace, screenshots, network log.
- For a single trace: `npx playwright show-trace test-results/<name>/trace.zip`.

## Layout

```
e2e/
├── playwright.config.ts   baseURL=http://localhost:8080, chromium-only, list+html
├── global-setup.ts        pings /api/cards/featured; fails fast if stack down
├── auth.setup.ts          logs in once, saves storageState
├── tests/                 specs run with that storageState pre-loaded
└── .auth/                 generated storage state (gitignored)
```

`node_modules` is a symlink to `../web/node_modules` — Playwright is already
a `devDependency` over there, so this directory adds zero install footprint.
