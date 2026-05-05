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
    // handlers naturally target the right row. The +1/-1 quantity
    // routing happens in the sidebar via _wantedEntry / _boundEntry.
    expect(merged.id).toBe(10)
    expect(merged.quantity).toBe(4)
    expect(merged.owned_quantity).toBe(2)
    expect(merged.wanted_quantity).toBe(2)
    expect(merged._split).toBe(true)
    expect(merged._wantedEntry.id).toBe(11)
    expect(merged._boundEntry.id).toBe(10)
  })

  it('annotates a purely-bound singleton with _canSplit', () => {
    const store = useDeckStore()
    store.entries = [
      entry({
        id: 20,
        scryfall_id: 'sid-bolt',
        quantity: 4,
        physical_copy_id: 200,
      }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0].id).toBe(20)
    expect(rows[0]._canSplit).toBe(true)
    // Wanted-only and unbound rows don't get _canSplit — the +1 path
    // in the sidebar already targets them directly.
    expect(rows[0]._split).toBeFalsy()
  })

  it('does not annotate wanted-only singletons with _canSplit', () => {
    const store = useDeckStore()
    store.entries = [
      entry({
        id: 30,
        scryfall_id: 'sid-bolt',
        quantity: 2,
        wanted: 'main',
      }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0]._canSplit).toBeFalsy()
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

  it('merges a fully-bound entry with a freshly-grown wanted sibling', () => {
    // Reproduces the user's bug report: clicking Wanted+ on a bound row
    // POSTs growWanted, which appends a wanted-only entry; the deck
    // list should coalesce that pair into one strip with /-separated
    // owned/wanted counts, not render two cards side by side.
    const store = useDeckStore()
    store.entries = [
      entry({
        id: 1,
        scryfall_id: 'sid-bolt',
        zone: 'main',
        quantity: 1,
        physical_copy_id: 99,
        wanted: null,
        category: 'Removal',
      }),
    ]
    // Simulate growWanted's POST response — backend creates a brand-new
    // wanted-only entry whose category came from autoCategory (not the
    // bound row's manual category).
    store.entries.push(entry({
      id: 2,
      scryfall_id: 'sid-bolt',
      zone: 'main',
      quantity: 1,
      physical_copy_id: null,
      wanted: 'main',
      category: 'instant',
    }))
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0]._split).toBe(true)
    expect(rows[0].owned_quantity).toBe(1)
    expect(rows[0].wanted_quantity).toBe(1)
  })

  it('merges a bound row with an unbound-no-wanted catalog row', () => {
    // The catalog-drag flow inserts an unbound row with `wanted: null`
    // (the observer only flips wanted=zone on a later grow). Pair it
    // with a bound row for the same card and the deck list must still
    // render one /-separated strip — never two side by side.
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-bolt', quantity: 1, physical_copy_id: 99, wanted: null }),
      entry({ id: 2, scryfall_id: 'sid-bolt', quantity: 1, physical_copy_id: null, wanted: null }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0]._split).toBe(true)
    expect(rows[0].owned_quantity).toBe(1)
    expect(rows[0].wanted_quantity).toBe(1)
  })

  it('coalesces multiple unbound rows for the same card with no bound twin', () => {
    // Stale historical state: the user has two unbound rows for the
    // same card but never bought one. Render as one strip with the
    // summed quantity instead of two stripes.
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-bolt', quantity: 2, physical_copy_id: null, wanted: 'main' }),
      entry({ id: 2, scryfall_id: 'sid-bolt', quantity: 1, physical_copy_id: null, wanted: null }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0].quantity).toBe(3)
    // No bound side, so the strip uses the regular qty pill — _split
    // should NOT be set.
    expect(rows[0]._split).toBeFalsy()
  })

  it('absorbs duplicate wanted siblings into one merged row', () => {
    // Defensive: if historical bugs left two wanted-only entries for
    // the same (scryfall_id, zone), the merge should still coalesce
    // them with the bound row instead of leaking a stray strip.
    const store = useDeckStore()
    store.entries = [
      entry({ id: 1, scryfall_id: 'sid-bolt', quantity: 2, physical_copy_id: 50, wanted: null }),
      entry({ id: 2, scryfall_id: 'sid-bolt', quantity: 1, physical_copy_id: null, wanted: 'main' }),
      entry({ id: 3, scryfall_id: 'sid-bolt', quantity: 1, physical_copy_id: null, wanted: 'main' }),
    ]
    const rows = store.mergedEntriesByZone('main')
    expect(rows).toHaveLength(1)
    expect(rows[0]._split).toBe(true)
    expect(rows[0].owned_quantity).toBe(2)
    expect(rows[0].wanted_quantity).toBe(2)
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
