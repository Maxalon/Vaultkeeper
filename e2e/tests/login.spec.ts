import { test, expect } from '@playwright/test'

test('authenticated visit to / lands on /collection', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/collection(\?|$)/)
})

test('manual login via the form lands on /collection', async ({ browser }) => {
  // Override the project-level storageState so this context starts logged out.
  const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
  const page = await ctx.newPage()
  await page.goto('/login')

  await page.locator('#login-username').fill('testuser')
  await page.locator('#login-password').fill('password')
  await page.getByRole('button', { name: /enter the vault/i }).click()

  await expect(page).toHaveURL(/\/collection(\?|$)/)
  await ctx.close()
})
