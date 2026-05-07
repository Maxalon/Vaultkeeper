# claude-sandbox

A standalone Vite + Playwright harness for testing production components
of `web/` against mock state — no backend, no live data, no real user
account. Designed so any developer (or Claude) can iterate on UI
behaviour and regressions in seconds, without ever shipping speculative
fixes through staging to find out what works.

The first home for this harness is the **location sidebar drag-and-drop**.
The framework is general — drop new fixtures and specs in here as
similar bugs come up.

## Layout

```
claude-sandbox/
├── README.md                  ← you are here
├── package.json               ← `"type": "module"` only; no deps
├── node_modules               ← symlink → ../web/node_modules
├── vite.config.js             ← Vite config; `root` = this directory
├── playwright.config.js       ← spawns the dev server, configures Chromium
│
├── index.html                 ← sidebar harness entry point
├── main.js                    ← mounts production LocationSidebar
│                                with mock Pinia state + stubbed axios
├── mocks/
│   ├── data.js                ← realistic-shape sidebar tree
│   └── api.js                 ← in-memory axios adapter; logs every move
│
├── control.html               ← positive-control fixture
├── control.js                 ← vanilla FormKit drag on a flat list
│
├── tests/
│   ├── control.spec.js        ← proves Playwright + FormKit work end-to-end
│   └── sidebar-drag.spec.js   ← regression suite for the actual sidebar
│
└── screenshots/               ← test output (artifacts + report)
```

## Running

From `web/`:

```bash
npm run sandbox          # serve harness at http://127.0.0.1:5174
npm run sandbox:test     # run the Playwright suite headlessly
```

The dev server is launched automatically by Playwright when running
the suite — no need to start it manually for tests.

## Why a harness instead of testing against staging?

The drag-and-drop bugs we kept chasing live entirely in the browser
(library wiring, focus-capture interactions, DOM/Vue reconciliation).
A harness with mock state lets us:

1. Reproduce a reported bug in seconds.
2. Iterate on the fix while the test runs in headless Chromium.
3. Confirm the fix lands before pushing — no more speculative deploys.

The trade-off is that the harness only catches bugs that live in
component logic. Anything that depends on real backend behaviour
(actual API responses, edge-case data shapes, throttling) still needs
a real staging deploy.

## Architecture decisions

**Symlinked `node_modules`.** The harness has its own Vite root but
shares dependencies with `web/`. A symlink keeps Node's resolution
simple and avoids version drift.

**`@web` alias.** `main.js` and friends import from
`@web/components/...` which resolves to `../web/src/...`. The harness
mounts the *real* production components, not copies — if those files
change, the next harness run reflects the change immediately.

**Mock state, not mock components.** Pinia stores are pre-populated
with a fixed tree (`mocks/data.js`) before mount. Axios's adapter is
swapped out for an in-memory implementation (`mocks/api.js`) that
records every move on `window.__moveLog`. Tests assert against that
log instead of against the network.

**Positive control.** `tests/control.spec.js` exercises a vanilla
FormKit drag on a flat list with no app code. If it ever fails, the
test infrastructure is broken and any failure on the real harness is
suspect — fix the control before drawing conclusions from anything else.

## Adding a new test

1. If the case requires new shapes in the tree, edit
   `mocks/data.js`. Keep IDs stable across runs so test selectors
   stay simple.
2. If the case requires new API endpoints, add a handler in
   `mocks/api.js`. Unmocked requests reject with 404 and a console
   warning so they show up loudly.
3. Write the spec under `tests/`. Use `dragHandle(page, fromSel,
   toSel, { position: 'before' | 'after' })` for sidebar drags;
   it handles the multiple-`pointermove`-step gesture FormKit needs
   to clear `remapJustFinished` on the first dragover.
4. Read `screenshots/<artifacts>/test-failed-1.png` on failure;
   Playwright captures one automatically.

## What we learned along the way

The sidebar's nested-row drag regressions were caused by FormKit's
default `handleNodeFocus`. It runs in capture phase and disables the
inner row's `draggable` attribute when focus on the row passes
through the outer group's node listener — *before* Chromium picks
the drag source. The fix: override the handler to a no-op in
`useSidebarSortable`. This harness made the diagnosis possible: the
control proved Playwright could drive FormKit drag correctly, the
sidebar harness reproduced the failure, and stepwise instrumentation
(walking the ancestor chain, dumping computed styles, attaching
capture-phase event listeners) pinpointed the focus handler as the
culprit.

Without this harness, that diagnosis would have taken half a dozen
staging deploys instead of an afternoon of headless test runs.
