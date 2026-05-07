import { defineConfig, devices } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '..')
const webRoot = path.resolve(repoRoot, 'web')

export default defineConfig({
  testDir: './tests',
  // Single worker keeps the harness's in-memory move log clean across
  // tests and avoids fighting over the same dev server port.
  workers: 1,
  fullyParallel: false,
  reporter: process.env.CI ? 'list' : [['list'], ['html', { outputFolder: path.resolve(__dirname, 'screenshots/_report'), open: 'never' }]],
  outputDir: path.resolve(__dirname, 'screenshots/_artifacts'),

  // Boot Vite ourselves and tear it down on exit. Vite is launched from
  // inside web/ so node_modules resolves naturally; the symlinked
  // node_modules in claude-sandbox/ keeps imports from this directory
  // working too.
  webServer: {
    command: `npx vite --config ${path.resolve(__dirname, 'vite.config.js')}`,
    cwd: webRoot,
    url: 'http://127.0.0.1:5174/',
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
    stdout: 'ignore',
    stderr: 'pipe',
  },

  use: {
    baseURL: 'http://127.0.0.1:5174/',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    ignoreHTTPSErrors: true,
    // Required for HTML5 drag-and-drop in headless Chromium. Without
    // this Playwright's `mouse.down/move/up` only fires mouse events,
    // not the dragstart/dragover/drop sequence the page expects.
    // Combined with using `page.mouse` for the gesture, this lets the
    // browser dispatch real DragEvents the way a user would.
    hasTouch: false,
    launchOptions: {
      args: ['--enable-features=TouchpadAndWheelScrollLatching'],
    },
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Use the headless-shell that's already on disk so we don't try
        // to download a build (the sandbox blocks Playwright's CDN).
      },
    },
  ],
})
