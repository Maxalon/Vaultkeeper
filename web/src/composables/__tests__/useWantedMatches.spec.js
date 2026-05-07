import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useWantedMatches } from '../useWantedMatches.js'

// Mock the api module so tests never hit the network.
vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
  },
}))

import api from '../../lib/api'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

afterEach(() => {
  vi.restoreAllMocks()
})

const FIXTURE = [
  {
    scryfall_card_id: 'card-a',
    card_name: 'Rhystic Study',
    wanted_quantity: 1,
    friends: [
      {
        user_id: 1,
        username: 'torque_wizard',
        available_copies: [
          { collection_entry_id: 101, condition: 'LP', foil: false, location_name: 'Binder' },
        ],
      },
    ],
  },
  {
    scryfall_card_id: 'card-b',
    card_name: 'Sol Ring',
    wanted_quantity: 2,
    friends: [],
  },
]

describe('useWantedMatches', () => {
  it('starts empty', () => {
    const wm = useWantedMatches()
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
    expect(wm.error.value).toBeNull()
  })

  it('fetch() populates matches on success', async () => {
    api.get.mockResolvedValueOnce({ data: FIXTURE })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(api.get).toHaveBeenCalledWith('/decks/42/wanted-matches')
    expect(wm.matches.value).toHaveLength(2)
    expect(wm.loading.value).toBe(false)
    expect(wm.error.value).toBeNull()
  })

  it('matchFor() returns correct entry by scryfall_card_id', async () => {
    api.get.mockResolvedValueOnce({ data: FIXTURE })
    const wm = useWantedMatches()
    await wm.fetch(42)
    const match = wm.matchFor('card-a')
    expect(match?.card_name).toBe('Rhystic Study')
    expect(match?.friends).toHaveLength(1)
  })

  it('matchFor() returns null for unknown card', async () => {
    api.get.mockResolvedValueOnce({ data: FIXTURE })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(wm.matchFor('does-not-exist')).toBeNull()
  })

  it('sets error on fetch failure', async () => {
    api.get.mockRejectedValueOnce({
      response: { data: { message: 'Server error' } },
    })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(wm.error.value).toBe('Server error')
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
  })

  it('uses fallback error message when response has no message', async () => {
    api.get.mockRejectedValueOnce(new Error('Network'))
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(wm.error.value).toContain('Failed to load')
  })

  it('does not double-fetch when already loading', async () => {
    let resolve
    api.get.mockReturnValueOnce(new Promise((r) => { resolve = r }))
    const wm = useWantedMatches()
    const p1 = wm.fetch(42)
    const p2 = wm.fetch(42) // should be no-op
    resolve({ data: FIXTURE })
    await Promise.all([p1, p2])
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('reset() clears all state', async () => {
    api.get.mockResolvedValueOnce({ data: FIXTURE })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(wm.matches.value).toHaveLength(2)
    wm.reset()
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
    expect(wm.error.value).toBeNull()
  })

  it('handles non-array response gracefully', async () => {
    api.get.mockResolvedValueOnce({ data: null })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(wm.matches.value).toEqual([])
    expect(wm.error.value).toBeNull()
  })
})
