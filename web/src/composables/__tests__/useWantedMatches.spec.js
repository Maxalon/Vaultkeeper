import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useWantedMatches } from '../useWantedMatches.js'

// Mock the api module so no real HTTP calls happen in tests.
vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
  },
}))

import api from '../../lib/api'
import fixture from '../../__mocks__/friends/wanted-matches.json'

// Helpers — fetch() now does two parallel api.get() calls:
//   [0] GET /decks/{id}/wanted-matches
//   [1] GET /friends
// We use mockResolvedValue with call-count tracking in tests that care.

const friendsFixture = [{ id: 42, username: 'torque_wizard' }]

/** Set up api.get to return matches + friends in order. */
function mockBothCalls({ matchData = fixture, friendData = friendsFixture } = {}) {
  api.get
    .mockResolvedValueOnce({ data: matchData })   // wanted-matches
    .mockResolvedValueOnce({ data: friendData })   // /friends
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('useWantedMatches', () => {
  it('starts with empty state', () => {
    const wm = useWantedMatches()
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
    expect(wm.error.value).toBeNull()
    expect(wm.friendCount.value).toBeNull()
    expect(wm.visibilityRevoked.value).toBe(false)
  })

  it('fetch() sets loading true then resolves matches', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    const p = wm.fetch(1)
    expect(wm.loading.value).toBe(true)
    await p
    expect(wm.loading.value).toBe(false)
    expect(wm.matches.value).toHaveLength(fixture.length)
  })

  it('fetch() calls the wanted-matches URL', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(42)
    expect(api.get).toHaveBeenCalledWith('/decks/42/wanted-matches')
  })

  it('fetch() also fetches /friends for friendCount', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(api.get).toHaveBeenCalledWith('/friends')
    expect(wm.friendCount.value).toBe(friendsFixture.length)
  })

  it('fetch() sets friendCount to 0 when /friends returns empty', async () => {
    mockBothCalls({ friendData: [] })
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.friendCount.value).toBe(0)
  })

  it('fetch() keeps friendCount null when /friends call fails', async () => {
    api.get
      .mockResolvedValueOnce({ data: fixture })    // wanted-matches OK
      .mockRejectedValueOnce(new Error('net'))      // /friends fails
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.matches.value).toHaveLength(fixture.length) // matches still loaded
    expect(wm.friendCount.value).toBeNull()               // friend count unknown
    expect(wm.error.value).toBeNull()                     // not a fatal error
  })

  it('matchFor() returns the match entry for a known card', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(1)
    const match = wm.matchFor(fixture[0].scryfall_card_id)
    expect(match).not.toBeNull()
    expect(match.card_name).toBe(fixture[0].card_name)
  })

  it('matchFor() returns null for an unknown card id', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.matchFor('not-a-real-id')).toBeNull()
  })

  it('fetch() is a no-op while another fetch is in flight', async () => {
    let resolveMatches
    let resolveFriends
    api.get
      .mockReturnValueOnce(new Promise((res) => { resolveMatches = res }))
      .mockReturnValueOnce(new Promise((res) => { resolveFriends = res }))
    const wm = useWantedMatches()
    wm.fetch(1) // starts, doesn't await
    await wm.fetch(1) // second call — should be a no-op
    // Only 2 calls total (from the first fetch's parallel requests, not 4).
    expect(api.get).toHaveBeenCalledTimes(2)
    resolveMatches({ data: [] })
    resolveFriends({ data: [] })
  })

  it('fetch() sets error when wanted-matches call fails', async () => {
    api.get
      .mockRejectedValueOnce({ response: { data: { message: 'Server error' } } })
      .mockResolvedValueOnce({ data: [] })
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.error.value).toBe('Server error')
    expect(wm.matches.value).toEqual([])
    expect(wm.loading.value).toBe(false)
  })

  it('fetch() uses fallback error message when response has no message', async () => {
    api.get
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce({ data: [] })
    const wm = useWantedMatches()
    await wm.fetch(1)
    expect(wm.error.value).toBe('Failed to load friend matches. Try again later.')
  })

  it('reset() clears all state including C4 fields', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(1)
    wm.markVisibilityRevoked()
    wm.reset()
    expect(wm.matches.value).toEqual([])
    expect(wm.error.value).toBeNull()
    expect(wm.loading.value).toBe(false)
    expect(wm.friendCount.value).toBeNull()
    expect(wm.visibilityRevoked.value).toBe(false)
  })

  it('markVisibilityRevoked() sets visibilityRevoked to true', () => {
    const wm = useWantedMatches()
    expect(wm.visibilityRevoked.value).toBe(false)
    wm.markVisibilityRevoked()
    expect(wm.visibilityRevoked.value).toBe(true)
  })

  it('fetch() clears visibilityRevoked flag', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    wm.markVisibilityRevoked()
    await wm.fetch(1)
    expect(wm.visibilityRevoked.value).toBe(false)
  })

  it('matchByCardId computed map has entries for all fixture cards', async () => {
    mockBothCalls()
    const wm = useWantedMatches()
    await wm.fetch(1)
    for (const m of fixture) {
      expect(wm.matchFor(m.scryfall_card_id)).not.toBeNull()
    }
  })
})
