import { describe, it, expect } from 'vitest'
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
