import { test as setup, expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { BASE_URL, STORAGE_STATE } from '../playwright.config'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

const USERNAME = process.env.E2E_USER ?? 'testuser'
const PASSWORD = process.env.E2E_PASS ?? 'password'

// Logs in once via the API, plants the JWT into localStorage, persists the
// resulting browser state to .auth/user.json. Every spec in the chromium
// project then reuses that state and skips the login UI.
setup('authenticate', async ({ page, request }) => {
  const res = await request.post(`${BASE_URL}/api/auth/login`, {
    data: { username: USERNAME, password: PASSWORD },
  })
  expect(res.ok(), `login failed: ${res.status()} ${await res.text()}`).toBeTruthy()
  const { access_token } = await res.json()
  expect(access_token, 'no access_token in /api/auth/login response').toBeTruthy()

  // localStorage is per-origin; navigate to baseURL before writing.
  await page.goto('/login')
  await page.evaluate((token) => localStorage.setItem('token', token), access_token)

  fs.mkdirSync(path.resolve(__dirname, '..', '.auth'), { recursive: true })
  await page.context().storageState({ path: STORAGE_STATE })
})
