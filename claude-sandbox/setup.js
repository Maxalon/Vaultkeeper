// Idempotent setup for the claude-sandbox harness.
//
// The harness imports the production app's components and dependencies
// from web/. Vite's module resolution walks up looking for
// `node_modules`, so we plant a symlink here that points at
// `../web/node_modules`. That avoids duplicating the dep tree while
// keeping the harness fully usable from `claude-sandbox/` as the Vite
// root.
//
// Run this once on a fresh checkout:
//   cd web && npm run sandbox:setup
// Or just rely on `sandbox` / `sandbox:test` invoking it for you.

import { existsSync, lstatSync, symlinkSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const link   = path.join(__dirname, 'node_modules')
const target = path.join('..', 'web', 'node_modules')

if (existsSync(link) || lstatSync(link, { throwIfNoEntry: false })) {
  console.log('[sandbox] node_modules link already present')
  process.exit(0)
}
const absoluteTarget = path.resolve(__dirname, target)
if (!existsSync(absoluteTarget)) {
  console.error(`[sandbox] expected dependencies in ${absoluteTarget}; run \`npm install\` in web/ first`)
  process.exit(1)
}
symlinkSync(target, link, 'dir')
console.log(`[sandbox] linked ${link} -> ${target}`)
