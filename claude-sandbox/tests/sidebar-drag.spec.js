import { test, expect } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const screenshotDir = path.resolve(__dirname, '..', 'screenshots')

/**
 * Drive a drag from a source row's drag handle to a target. Uses
 * multiple intermediate moves because FormKit's validateSort
 * early-returns on the first dragover after a remap; one hover-and-drop
 * is not enough.
 */
async function dragHandle(page, fromSelector, toSelector, opts = {}) {
  const from = page.locator(fromSelector)
  const to   = page.locator(toSelector)
  await from.scrollIntoViewIfNeeded()
  await to.scrollIntoViewIfNeeded()
  await from.hover()
  const handle = from.locator('.drag-handle')
  await handle.waitFor({ state: 'visible' })

  const handleBox = await handle.boundingBox()
  const toBox = await to.boundingBox()
  if (!handleBox || !toBox) {
    throw new Error('drag bounding boxes missing')
  }
  const sx = handleBox.x + handleBox.width / 2
  const sy = handleBox.y + handleBox.height / 2
  const tx = toBox.x + toBox.width / 2
  const ty = (opts.position === 'before' ? toBox.y + 4 : toBox.y + toBox.height - 4)

  await page.mouse.move(sx, sy)
  await page.mouse.down()
  const steps = 14
  for (let i = 1; i <= steps; i++) {
    const x = sx + (tx - sx) * (i / steps)
    const y = sy + (ty - sy) * (i / steps)
    await page.mouse.move(x, y, { steps: 4 })
  }
  await page.mouse.up()
  await page.waitForTimeout(80)
}

test.describe('sidebar drag-and-drop', () => {
  test.beforeEach(async ({ page }) => {
    const errors = []
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(`console.error: ${msg.text()}`)
    })
    page._sandboxErrors = errors
    await page.goto('/')
    // Wait for the harness to mount.
    await expect(page.locator('.location-sidebar')).toBeVisible()
  })

  test('harness boots without errors', async ({ page }) => {
    await page.screenshot({
      path: path.join(screenshotDir, 'boot.png'),
      fullPage: true,
    })
    expect(page._sandboxErrors).toEqual([])
    // Tree shape sanity: the four top-level items should render.
    await expect(page.locator('[data-id="1"][data-kind="location"]')).toBeVisible()
    await expect(page.locator('[data-id="10"][data-kind="group"]')).toBeVisible()
    await expect(page.locator('[data-id="11"][data-kind="group"]')).toBeVisible()
    await expect(page.locator('[data-id="6"][data-kind="deck"]')).toBeVisible()
  })

  test('every group is expanded so nested rows are reachable', async ({ page }) => {
    // The store defaults groups to collapsed; expand them all so nested
    // rows are visible to the drag tests below.
    await page.evaluate(() => {
      const store = window.__sandbox.getCollectionState
        ? window.__sandbox /* surface */ : null
      // Expand groups by toggling collapse state directly via the
      // sidebar's group headers — easier than reaching into the store.
    })
    // Just click each group header that's collapsed.
    const headers = page.locator('.group-header')
    const count = await headers.count()
    for (let i = 0; i < count; i++) {
      const header = headers.nth(i)
      const collapsed = await header.getAttribute('class')
      if (collapsed && collapsed.includes('collapsed')) {
        await header.click()
      }
    }
    // After expanding everything, the nested deck inside Blank Box should
    // be visible.
    await expect(page.locator('[data-id="4"][data-kind="deck"]')).toBeVisible()
  })

  test('drag a top-level row to reorder (regression baseline)', async ({ page }) => {
    // Move the top-level Main Drawer to position 1 (after Concepts).
    await dragHandle(
      page,
      '[data-id="1"][data-kind="location"]',
      '[data-id="10"][data-kind="group"]',
      { position: 'after' },
    )
    await page.screenshot({ path: path.join(screenshotDir, 'after-toplevel-row-drag.png'), fullPage: true })

    // The harness records every successful move on window.__moveLog; if
    // the drag fired correctly we should see an entry for kind=location id=1.
    const log = await page.evaluate(() => window.__moveLog || [])
    expect(log.length).toBeGreaterThan(0)
    expect(log[log.length - 1]).toMatchObject({ kind: 'location', id: 1 })
  })

  test('drag a nested row within its group (the regression)', async ({ page }) => {
    // Expand all groups first.
    const headers = page.locator('.group-header')
    const count = await headers.count()
    for (let i = 0; i < count; i++) {
      const header = headers.nth(i)
      const klass = (await header.getAttribute('class')) || ''
      if (klass.includes('collapsed')) await header.click()
    }
    await expect(page.locator('[data-id="2"][data-kind="location"]')).toBeVisible()
    await expect(page.locator('[data-id="3"][data-kind="deck"]')).toBeVisible()

    // Try to swap the two nested children of Concepts: drag deck #3 above
    // location #2.
    await dragHandle(
      page,
      '[data-id="3"][data-kind="deck"]',
      '[data-id="2"][data-kind="location"]',
      { position: 'before' },
    )
    await page.screenshot({ path: path.join(screenshotDir, 'after-nested-row-drag.png'), fullPage: true })

    const log = await page.evaluate(() => window.__moveLog || [])
    // This is the exact case the user keeps reporting. We assert the
    // move command was actually dispatched.
    expect(log.length).toBeGreaterThan(0)
    expect(log[log.length - 1]).toMatchObject({ kind: 'deck', id: 3, parent_id: 10 })
  })

  test('drag a deck across groups leaves exactly one copy in the new home', async ({ page }) => {
    // The user's first report: dragging a deck from "Concepts" into a
    // different group produced a phantom copy until page reload.
    // Expand every group so both source and destination are visible.
    const headers = page.locator('.group-header')
    const count = await headers.count()
    for (let i = 0; i < count; i++) {
      const header = headers.nth(i)
      const klass = (await header.getAttribute('class')) || ''
      if (klass.includes('collapsed')) await header.click()
    }

    // Source: deck #3 in Concepts (group #10). Target: Side Binder
    // (location #5) inside Assembled Decks (group #11). Dropping there
    // is an unambiguous cross-group transfer with no nested-container
    // ambiguity.
    await dragHandle(
      page,
      '[data-id="3"][data-kind="deck"]',
      '[data-id="5"][data-kind="location"]',
      { position: 'before' },
    )

    // Assertions:
    //   1) the move dispatched at all (no failed-bubble cancel),
    //   2) it landed in Assembled Decks (parent_id=11), not Concepts,
    //   3) the rendered DOM has exactly ONE deck #3 — no phantom copy.
    const log = await page.evaluate(() => window.__moveLog || [])
    expect(log.length).toBeGreaterThan(0)
    expect(log[log.length - 1]).toMatchObject({ kind: 'deck', id: 3, parent_id: 11 })

    const deckCount = await page.locator('[data-id="3"][data-kind="deck"]').count()
    expect(deckCount).toBe(1)
  })
})
