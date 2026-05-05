import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useDeckStore } from '../deck.js'

beforeEach(() => {
  setActivePinia(createPinia())
})

function entry(overrides = {}) {
  return {
    id: overrides.id ?? Math.floor(Math.random() * 1e6),
    deck_id: 1,
    scryfall_id: overrides.scryfall_id ?? 'sid-default',
    quantity: overrides.quantity ?? 1,
    zone: overrides.zone ?? 'main',
    is_commander: false,
    is_signature_spell: false,
    physical_copy_id: null,
    wanted: null,
    scryfall_card: { name: overrides.name ?? 'Card' },
    ...overrides,
  }
}

describe('mergedEntriesByZone', () => {
  it('returns entries unchanged when no split pair is present', () => {
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-bolt', quantity: 4 }),
      entry({ id: 2, scryfall_id: 'sid-mountain', quantity: 4 }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows.map((r) => r.id).sort()).toEqual([1, 2])
    expect(rows.every((r) => !r._split)).toBe(true)
  })

  it('coalesces a partial-exclude split into one merged row', () => {
    const store = useDeckStore()
    store.entries = [
      entry({
        id: 10,
        scryfall_id: 'sid-mountain',
        quantity: 2,
        physical_copy_id: 99,
      }),
      entry({
        id: 11,
        scryfall_id: 'sid-mountain',
        quantity: 2,
        wanted: 'main',
      }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    const merged = rows[0]
    // The merged row carries the bound entry's id so existing edit
    // handlers naturally target the right row.
    expect(merged.id).toBe(10)
    expect(merged.quantity).toBe(4)
    expect(merged.owned_quantity).toBe(2)
    expect(merged.wanted_quantity).toBe(2)
    expect(merged._split).toBe(true)
    expect(merged._wantedEntry.id).toBe(11)
  })

  it('does not merge across zones', () => {
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-bolt', zone: 'main', quantity: 2, physical_copy_id: 100 }),
      entry({ id: 2, scryfall_id: 'sid-bolt', zone: 'side', quantity: 2, wanted: 'side' }),
    ]
    const main = store.mergedEntriesByZone('main')
    const side = store.mergedEntriesByZone('side')
    expect(main).toHaveLength(1)
    expect(side).toHaveLength(1)
    expect(main[0]._split).toBeFalsy()
    expect(side[0]._split).toBeFalsy()
  })

  it('skips merging when both rows are bound (no wanted sibling)', () => {
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-mountain', quantity: 2, physical_copy_id: 50 }),
      entry({ id: 2, scryfall_id: 'sid-mountain', quantity: 2, physical_copy_id: 51 }),
    ]
    const rows = store.mergedEntriesByZone('main')
    // Two distinct bound rows is malformed but shouldn't crash; the
    // getter just leaves them as-is rather than guessing which to merge.
    expect(rows).toHaveLength(2)
    expect(rows.every((r) => !r._split)).toBe(true)
  })

  it('excludes commanders and signature spells from merging', () => {
    const store = useDeckStore()
    store.entries = [
      entry({
        id: 1,
        scryfall_id: 'sid-atraxa',
        quantity: 1,
        is_commander: true,
        physical_copy_id: 70,
      }),
      entry({
        id: 2,
        scryfall_id: 'sid-atraxa',
        quantity: 1,
        wanted: 'main',
      }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows.every((r) => !r._split)).toBe(true)
  })
})
