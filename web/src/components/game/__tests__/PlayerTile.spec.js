import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PlayerTile from '../PlayerTile.vue'

function mountTile(props = {}) {
  return mount(PlayerTile, {
    props: { name: 'Alice', life: 40, ...props },
    attachTo: document.body,
  })
}

describe('PlayerTile', () => {
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

  it('is not rotated by default', () => {
    const w = mountTile()
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
  })

  it('auto-rotate prop starts tile rotated', () => {
    const w = mountTile({ autoRotate: true })
    expect(w.find('[data-testid="player-tile"]').classes()).toContain('rotated')
  })

  it('rotate button toggles rotation off when autoRotate is true', async () => {
    const w = mountTile({ autoRotate: true })
    expect(w.find('[data-testid="player-tile"]').classes()).toContain('rotated')
    await w.find('[data-testid="rotate-btn"]').trigger('click')
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
  })

  it('rotate button toggles rotation on when autoRotate is false', async () => {
    const w = mountTile({ autoRotate: false })
    expect(w.find('[data-testid="player-tile"]').classes()).not.toContain('rotated')
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

  it('has upper and lower tap zone elements', () => {
    const w = mountTile()
    expect(w.find('[data-testid="tap-upper"]').exists()).toBe(true)
    expect(w.find('[data-testid="tap-lower"]').exists()).toBe(true)
  })
})

describe('PlayerTile — poison badge', () => {
  beforeEach(() => { vi.useFakeTimers() })
  afterEach(() => { vi.useRealTimers() })

  it('renders poison badge with count 0 by default', () => {
    const w = mountTile()
    const badge = w.find('[data-testid="poison-badge"]')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toContain('0')
  })

  it('renders poison count from prop', () => {
    const w = mountTile({ poison: 7 })
    expect(w.find('[data-testid="poison-badge"]').text()).toContain('7')
  })

  it('does not apply ko class below 10', () => {
    const w = mountTile({ poison: 9 })
    expect(w.find('[data-testid="poison-badge"]').classes()).not.toContain('ko')
  })

  it('applies ko class at 10', () => {
    const w = mountTile({ poison: 10 })
    expect(w.find('[data-testid="poison-badge"]').classes()).toContain('ko')
  })

  it('applies ko class above 10', () => {
    const w = mountTile({ poison: 12 })
    expect(w.find('[data-testid="poison-badge"]').classes()).toContain('ko')
  })

  it('emits adjust-poison +1 on tap (pointerdown then quick pointerup)', async () => {
    const w = mountTile({ poison: 3 })
    const badge = w.find('[data-testid="poison-badge"]')
    await badge.trigger('pointerdown')
    await badge.trigger('pointerup')
    expect(w.emitted('adjust-poison')).toEqual([[1]])
  })

  it('emits adjust-poison -1 on long-press (pointerdown held >= 500ms)', async () => {
    const w = mountTile({ poison: 3 })
    const badge = w.find('[data-testid="poison-badge"]')
    await badge.trigger('pointerdown')
    vi.advanceTimersByTime(500)
    expect(w.emitted('adjust-poison')).toEqual([[-1]])
  })

  it('does not emit tap after long-press fires', async () => {
    const w = mountTile({ poison: 3 })
    const badge = w.find('[data-testid="poison-badge"]')
    await badge.trigger('pointerdown')
    vi.advanceTimersByTime(500)
    await badge.trigger('pointerup')
    expect(w.emitted('adjust-poison')).toHaveLength(1)
    expect(w.emitted('adjust-poison')[0]).toEqual([-1])
  })

  it('cancels long-press timer on pointerleave and does not emit', async () => {
    const w = mountTile({ poison: 3 })
    const badge = w.find('[data-testid="poison-badge"]')
    await badge.trigger('pointerdown')
    await badge.trigger('pointerleave')
    vi.advanceTimersByTime(600)
    expect(w.emitted('adjust-poison')).toBeFalsy()
  })
})
