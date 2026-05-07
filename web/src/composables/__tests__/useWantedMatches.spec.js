import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { useWantedMatches } from '../useWantedMatches.js'

// Mock the api module so no real HTTP calls happen in tests.
vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
  },
}))

import api from '../../lib/api'
import fixture from '../../__mocks__/friends/wanted-matches.json'

beforeEach(() => {
  vi.clearAllMocks()
})

describe('useWantedMatches', () => {
  it('starts with empty state', () => {
    const wm = useWantedMatches()
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
    expect(wm.error.value).toBeNull()
  })

  it('fetch() sets loading true then resolves matches', async () => {
    api.get.mockResolvedValueOnce({ data: fixture })
    const wm = useWantedMatches()
    const p = wm.fetch(1)
    expect(wm.loading.value).toBe(true)
    await p
    expect(wm.loading.value).toBe(false)
    expect(wm.matches.value).toHaveLength(fixture.length)
  })

  it('fetch() calls the correct URL', async () => {
    api.get.mockResolvedValueOnce({ data: [] })
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(api.get).toHaveBeenCalledWith('/decks/42/wanted-matches')
  })

  it('matchFor() returns the match entry for a known card', async () => {
    api.get.mockResolvedValueOnce({ data: fixture })
    const wm = useWantedMatches()
    await wm.fetch(1)
    const match = wm.matchFor(fixture[0].scryfall_card_id)
    expect(match).not.toBeNull()
    expect(match.card_name).toBe(fixture[0].card_name)
  })

  it('matchFor() returns null for an unknown card id', async () => {
    api.get.mockResolvedValueOnce({ data: fixture })
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.matchFor('not-a-real-id')).toBeNull()
  })

  it('fetch() is a no-op while another fetch is in flight', async () => {
    let resolveFn
    api.get.mockReturnValueOnce(new Promise((res) => { resolveFn = res }))
    const wm = useWantedMatches()
    wm.fetch(1) // starts, doesn't await
    await wm.fetch(1) // second call — should be a no-op
    expect(api.get).toHaveBeenCalledTimes(1)
    resolveFn({ data: [] })
  })

  it('fetch() sets error on API failure', async () => {
    api.get.mockRejectedValueOnce({ response: { data: { message: 'Server error' } } })
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.error.value).toBe('Server error')
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
  })

  it('fetch() uses fallback error message when response has no message', async () => {
    api.get.mockRejectedValueOnce(new Error('network'))
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.error.value).toBe('Failed to load friend matches. Try again later.')
  })

  it('reset() clears all state', async () => {
    api.get.mockResolvedValueOnce({ data: fixture })
    const wm = useWantedMatches()
    await wm.fetch(1)
    wm.reset()
    expect(wm.matches.value).toEqual([])
    expect(wm.error.value).toBeNull()
    expect(wm.loading.value).toBe(false)
  })

  it('matchByCardId computed map has entries for all fixture cards', async () => {
    api.get.mockResolvedValueOnce({ data: fixture })
    const wm = useWantedMatches()
    await wm.fetch(1)
    for (const m of fixture) {
      expect(wm.matchFor(m.scryfall_card_id)).not.toBeNull()
    }
  })
})
