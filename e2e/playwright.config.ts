import { defineConfig, devices } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8080'
export const STORAGE_STATE = path.resolve(__dirname, '.auth/user.json')

export default defineConfig({
  testDir: './tests',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI
    ? 'list'
    : [['list'], ['html', { outputFolder: path.resolve(__dirname, 'playwright-report'), open: 'never' }]],
  outputDir: path.resolve(__dirname, 'test-results'),

  globalSetup: path.resolve(__dirname, 'global-setup.ts'),

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts$/,
    },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
      dependencies: ['setup'],
    },
  ],
})
