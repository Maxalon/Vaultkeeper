import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))
vi.mock('../collection', () => ({
  useCollectionStore: () => ({ fetchGroups: vi.fn(), fetchDecks: vi.fn() }),
}))
vi.mock('../prices', () => ({
  usePricesStore: () => ({ scheduleRefresh: vi.fn() }),
}))
vi.mock('../../composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), withActions: vi.fn() }),
}))

import api from '../../lib/api'
import { useDeckStore } from '../deck.js'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

const baseDeck = {
  id: 1,
  name: 'Test',
  format: 'commander',
  commander1: null,
  commander2: null,
}

describe('deck.updateDeck', () => {
  it('reloads entries when a commander slot is patched (promote)', async () => {
    const store = useDeckStore()
    store.deck = baseDeck
    // Stale local entries: the server flips is_commander on this entry,
    // but the local copy still says false until we reload.
    store.entries = [
      { id: 10, scryfall_id: 'sid-cmdr', is_commander: false, zone: 'main', quantity: 1 },
    ]

    api.put.mockResolvedValueOnce({
      data: { ...baseDeck, commander1: { scryfall_id: 'sid-cmdr', name: 'Cmdr' } },
    })
    api.get
      // loadIllegalities
      .mockResolvedValueOnce({ data: [] })
      // loadEntries — server says this entry is now is_commander
      .mockResolvedValueOnce({
        data: [{ id: 10, scryfall_id: 'sid-cmdr', is_commander: true, zone: 'main', quantity: 1 }],
      })

    await store.updateDeck(1, { commander_1_scryfall_id: 'sid-cmdr' })

    expect(api.get).toHaveBeenCalledWith('/decks/1/entries', { params: { sort: 'name' } })
    expect(store.entries[0].is_commander).toBe(true)
  })

  it('reloads entries when a commander slot is cleared (demote)', async () => {
    const store = useDeckStore()
    store.deck = { ...baseDeck, commander1: { scryfall_id: 'sid-cmdr', name: 'Cmdr' } }
    store.entries = [
      { id: 10, scryfall_id: 'sid-cmdr', is_commander: true, zone: 'main', quantity: 1 },
    ]

    api.put.mockResolvedValueOnce({ data: { ...baseDeck, commander1: null } })
    api.get
      .mockResolvedValueOnce({ data: [] })
      .mockResolvedValueOnce({
        data: [{ id: 10, scryfall_id: 'sid-cmdr', is_commander: false, zone: 'main', quantity: 1 }],
      })

    await store.updateDeck(1, { commander_1_scryfall_id: null })

    expect(store.entries[0].is_commander).toBe(false)
  })

  it('does not reload entries for unrelated patches (e.g. name change)', async () => {
    const store = useDeckStore()
    store.deck = baseDeck

    api.put.mockResolvedValueOnce({ data: { ...baseDeck, name: 'Renamed' } })
    api.get.mockResolvedValueOnce({ data: [] }) // illegalities only

    await store.updateDeck(1, { name: 'Renamed' })

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledWith('/decks/1/illegalities')
  })
})
