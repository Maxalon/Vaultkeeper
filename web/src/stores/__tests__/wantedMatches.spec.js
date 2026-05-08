import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useWantedMatchesStore } from '../wantedMatches.js'

vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
  },
}))

import api from '../../lib/api'
import fixture from '../../__mocks__/friends/wanted-matches.json'

const friendsFixture = [{ id: 42, username: 'torque_wizard' }]

function mockBothCalls({ matchData = fixture, friendData = friendsFixture } = {}) {
  api.get
    .mockResolvedValueOnce({ data: matchData })
    .mockResolvedValueOnce({ data: friendData })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('wantedMatches store', () => {
  it('starts with empty state', () => {
    const wm = useWantedMatchesStore()
    expect(wm.matches).toEqual([])
    expect(wm.loading).toBe(false)
    expect(wm.error).toBeNull()
    expect(wm.friendCount).toBeNull()
    expect(wm.visibilityRevoked).toBe(false)
    expect(wm.activeMatch).toBeNull()
  })

  it('fetch() flips loading then resolves matches', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    const p = wm.fetch(1)
    expect(wm.loading).toBe(true)
    await p
    expect(wm.loading).toBe(false)
    expect(wm.matches).toHaveLength(fixture.length)
  })

  it('fetch() calls the wanted-matches URL', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(42)
    expect(api.get).toHaveBeenCalledWith('/decks/42/wanted-matches')
  })

  it('fetch() also fetches /friends for friendCount', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(api.get).toHaveBeenCalledWith('/friends')
    expect(wm.friendCount).toBe(friendsFixture.length)
  })

  it('fetch() sets friendCount to 0 when /friends returns empty', async () => {
    mockBothCalls({ friendData: [] })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.friendCount).toBe(0)
  })

  it('fetch() supports the {data: [...]} response shape for /friends', async () => {
    api.get
      .mockResolvedValueOnce({ data: fixture })
      .mockResolvedValueOnce({ data: { data: friendsFixture } })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.friendCount).toBe(friendsFixture.length)
  })

  it('fetch() keeps friendCount null when /friends call fails', async () => {
    api.get
      .mockResolvedValueOnce({ data: fixture })
      .mockRejectedValueOnce(new Error('net'))
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.matches).toHaveLength(fixture.length)
    expect(wm.friendCount).toBeNull()
    expect(wm.error).toBeNull()
  })

  it('matchFor() returns the match entry for a known card', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    const match = wm.matchFor(fixture[0].scryfall_card_id)
    expect(match).not.toBeNull()
    expect(match.card_name).toBe(fixture[0].card_name)
  })

  it('matchFor() returns null for an unknown card id', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.matchFor('not-a-real-id')).toBeNull()
  })

  it('fetch() is a no-op while another fetch is in flight', async () => {
    let resolveMatches
    let resolveFriends
    api.get
      .mockReturnValueOnce(new Promise((res) => { resolveMatches = res }))
      .mockReturnValueOnce(new Promise((res) => { resolveFriends = res }))
    const wm = useWantedMatchesStore()
    wm.fetch(1)
    await wm.fetch(1)
    expect(api.get).toHaveBeenCalledTimes(2)
    resolveMatches({ data: [] })
    resolveFriends({ data: [] })
  })

  it('fetch() sets error when wanted-matches call fails', async () => {
    api.get
      .mockRejectedValueOnce({ response: { data: { message: 'Server error' } } })
      .mockResolvedValueOnce({ data: [] })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.error).toBe('Server error')
    expect(wm.matches).toEqual([])
    expect(wm.loading).toBe(false)
  })

  it('fetch() uses fallback error message when response has no message', async () => {
    api.get
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce({ data: [] })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.error).toBe('Failed to load friend matches. Try again later.')
  })

  it('reset() clears all state', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    wm.markVisibilityRevoked()
    wm.openPanel(wm.matches[0])
    wm.reset()
    expect(wm.matches).toEqual([])
    expect(wm.error).toBeNull()
    expect(wm.loading).toBe(false)
    expect(wm.friendCount).toBeNull()
    expect(wm.visibilityRevoked).toBe(false)
    expect(wm.activeMatch).toBeNull()
  })

  it('markVisibilityRevoked() sets visibilityRevoked to true', () => {
    const wm = useWantedMatchesStore()
    expect(wm.visibilityRevoked).toBe(false)
    wm.markVisibilityRevoked()
    expect(wm.visibilityRevoked).toBe(true)
  })

  it('fetch() clears visibilityRevoked flag', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    wm.markVisibilityRevoked()
    await wm.fetch(1)
    expect(wm.visibilityRevoked).toBe(false)
  })

  it('matchByCardId getter has entries for all fixture cards', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    for (const m of fixture) {
      expect(wm.matchFor(m.scryfall_card_id)).not.toBeNull()
    }
  })

  it('openPanel(match) sets activeMatch directly when given a match object', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    wm.openPanel(fixture[0])
    expect(wm.activeMatch).toStrictEqual(fixture[0])
  })

  it('openPanel(entry) wraps a deck entry into a synthetic match when no match exists', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    const synthetic = {
      scryfall_id: 'unknown-card-id',
      scryfall_card: { name: 'Unknown Card' },
      wanted_quantity: 2,
    }
    wm.openPanel(synthetic)
    expect(wm.activeMatch).toEqual({
      scryfall_card_id: 'unknown-card-id',
      card_name: 'Unknown Card',
      wanted_quantity: 2,
      friends: [],
    })
  })

  it('closePanel() resets activeMatch', async () => {
    mockBothCalls()
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    wm.openPanel(fixture[0])
    wm.closePanel()
    expect(wm.activeMatch).toBeNull()
  })

  it('noVisibleFriends getter is true only when friends exist but no matches', async () => {
    api.get
      .mockResolvedValueOnce({ data: fixture.map((m) => ({ ...m, friends: [] })) })
      .mockResolvedValueOnce({ data: friendsFixture })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.noVisibleFriends).toBe(true)
  })

  it('noVisibleFriends getter is false when friendCount is 0', async () => {
    mockBothCalls({ friendData: [] })
    const wm = useWantedMatchesStore()
    await wm.fetch(1)
    expect(wm.noVisibleFriends).toBe(false)
  })
})
