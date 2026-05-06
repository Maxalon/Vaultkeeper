import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import svgLoader from 'vite-svg-loader'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '..')
const webRoot = path.resolve(repoRoot, 'web')

// Vite config for the sandbox harness.
//
// `root` is this directory, so the harness HTML/JS live alongside the
// rest of `claude-sandbox/` and the production app stays untouched.
// Module resolution falls back to the symlinked node_modules in this
// directory, which points at `web/node_modules` — no duplication of
// dependencies.
//
// `@web` aliases to the production source so the harness mounts the
// real components rather than copies. `server.fs.allow` opens up reads
// outside this directory (Vite blocks that by default for safety).
export default defineConfig({
  root: __dirname,
  publicDir: false,
  plugins: [vue(), svgLoader()],
  resolve: {
    alias: {
      '@web': path.resolve(webRoot, 'src'),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5174,
    strictPort: true,
    fs: {
      // Allow Vite to read source files from web/ as well as this
      // sandbox directory.
      allow: [__dirname, repoRoot],
    },
  },
  // Keep the dep-prebundle cache out of web/ so the production build
  // and the harness don't fight over the same cache.
  cacheDir: path.resolve(__dirname, 'node_modules/.vite-sandbox'),
})
