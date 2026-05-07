import { test, expect } from '@playwright/test'

/**
 * Drag the source onto the target with multiple intermediate dragover
 * events. FormKit's validateSort early-returns on the first dragover
 * after a remap (consuming `remapJustFinished`), so a single hover
 * isn't enough for a same-list reorder — the user always generates a
 * stream of dragover events as they move; we fake that here.
 */
async function dragOverTo(page, source, target, opts = {}) {
  const sourceBox = await source.boundingBox()
  const targetBox = await target.boundingBox()
  if (!sourceBox || !targetBox) throw new Error('drag bounding boxes missing')
  const sx = sourceBox.x + sourceBox.width / 2
  const sy = sourceBox.y + sourceBox.height / 2
  const tx = targetBox.x + targetBox.width / 2
  const ty = (opts.position === 'before' ? targetBox.y + 4 : targetBox.y + targetBox.height - 4)

  await source.hover({ force: true })
  await page.mouse.down()
  // Multiple intermediate steps so FormKit sees several dragover events
  // and exits `remapJustFinished` mode after the first.
  const steps = 12
  for (let i = 1; i <= steps; i++) {
    const x = sx + (tx - sx) * (i / steps)
    const y = sy + (ty - sy) * (i / steps)
    await page.mouse.move(x, y, { steps: 4 })
  }
  await page.mouse.up()
}

// Positive control. If THIS test fails, the test infrastructure itself
// (Playwright, browser, our drag mechanism) is broken — anything else
// in this suite is suspect until we get this green.
test.describe('control · vanilla FormKit drag', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/control.html')
    await page.waitForSelector('#list-a li[data-id="a1"]')
  })

  test('reorder within a single list', async ({ page }) => {
    const apple   = page.locator('#list-a li[data-id="a1"]')
    const avocado = page.locator('#list-a li[data-id="a3"]')
    await dragOverTo(page, apple, avocado)
    await page.waitForTimeout(150)
    const log = await page.evaluate(() => window.__controlLog || [])
    expect(log.length).toBeGreaterThan(0)
    expect(log[log.length - 1].kind).toBe('sort')
  })

  test('transfer between two lists', async ({ page }) => {
    const apple = page.locator('#list-a li[data-id="a1"]')
    const listB = page.locator('#list-b')
    await apple.dragTo(listB, { force: true })
    await page.waitForTimeout(100)
    const log = await page.evaluate(() => window.__controlLog || [])
    expect(log.length).toBeGreaterThan(0)
    expect(log.some((e) => e.kind === 'transfer-out' || e.kind === 'transfer-in')).toBe(true)
  })
})
