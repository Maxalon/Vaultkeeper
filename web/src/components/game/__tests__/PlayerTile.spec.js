import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PlayerTile from '../PlayerTile.vue'

afterEach(() => {
  vi.useRealTimers()
})

function mountTile(props = {}) {
  return mount(PlayerTile, {
    props: { name: 'Alice', life: 40, ...props },
    attachTo: document.body,
  })
}

describe('PlayerTile — display', () => {
  it('renders name and life total', () => {
    const w = mountTile({ name: 'Bob', life: 27 })
    expect(w.find('.player-name').text()).toBe('Bob')
    expect(w.find('[data-testid="life-total"]').text()).toBe('27')
  })

  it('applies dead class when life ≤ 0', () => {
    const w = mountTile({ life: 0 })
    expect(w.find('[data-testid="life-total"]').classes()).toContain('dead')
  })

  it('does not apply dead class when life is positive', () => {
    const w = mountTile({ life: 1 })
    expect(w.find('[data-testid="life-total"]').classes()).not.toContain('dead')
  })

  it('has upper and lower tap zone elements', () => {
    const w = mountTile()
    expect(w.find('[data-testid="tap-upper"]').exists()).toBe(true)
    expect(w.find('[data-testid="tap-lower"]').exists()).toBe(true)
  })
})

describe('PlayerTile — rotation', () => {
  it('is not rotated by default', () => {
    const w = mountTile()
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
  })

  it('autoRotate prop starts tile rotated', () => {
    const w = mountTile({ autoRotate: true })
    expect(w.find('[data-testid="player-tile"]').classes()).toContain('rotated')
  })

  it('rotate button toggles rotation off when autoRotate is true', async () => {
    const w = mountTile({ autoRotate: true })
    await w.find('[data-testid="rotate-btn"]').trigger('click')
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
  })

  it('rotate button toggles rotation on when autoRotate is false', async () => {
    const w = mountTile({ autoRotate: false })
    await w.find('[data-testid="rotate-btn"]').trigger('click')
    expect(w.find('[data-testid="player-tile"]').classes()).toContain('rotated')
  })

  it('second rotate button click restores original rotation state', async () => {
    const w = mountTile({ autoRotate: false })
    const btn = w.find('[data-testid="rotate-btn"]')
    await btn.trigger('click')
    await btn.trigger('click')
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
  })
})

describe('PlayerTile — life adjustment', () => {
  it('pointerdown on upper tap zone emits adjust +1', async () => {
    const w = mountTile()
    await w.find('[data-testid="tap-upper"]').trigger('pointerdown')
    w.find('[data-testid="tap-upper"]').trigger('pointerup')
    expect(w.emitted('adjust')).toBeTruthy()
    expect(w.emitted('adjust')[0]).toEqual([1])
  })

  it('pointerdown on lower tap zone emits adjust -1', async () => {
    const w = mountTile()
    await w.find('[data-testid="tap-lower"]').trigger('pointerdown')
    w.find('[data-testid="tap-lower"]').trigger('pointerup')
    expect(w.emitted('adjust')[0]).toEqual([-1])
  })

  it('when rotated upper tap emits -1 (sign flips for across-table player)', async () => {
    const w = mountTile({ autoRotate: true })
    await w.find('[data-testid="tap-upper"]').trigger('pointerdown')
    w.find('[data-testid="tap-upper"]').trigger('pointerup')
    expect(w.emitted('adjust')[0]).toEqual([-1])
  })

  it('when rotated lower tap emits +1', async () => {
    const w = mountTile({ autoRotate: true })
    await w.find('[data-testid="tap-lower"]').trigger('pointerdown')
    w.find('[data-testid="tap-lower"]').trigger('pointerup')
    expect(w.emitted('adjust')[0]).toEqual([1])
  })

  it('pointerleave stops the hold (no further emits after leave)', async () => {
    vi.useFakeTimers()
    const w = mountTile()
    await w.find('[data-testid="tap-upper"]').trigger('pointerdown')
    await w.find('[data-testid="tap-upper"]').trigger('pointerleave')
    const countAfterLeave = w.emitted('adjust')?.length ?? 0
    vi.advanceTimersByTime(3000)
    expect(w.emitted('adjust')?.length ?? 0).toBe(countAfterLeave)
  })
})
