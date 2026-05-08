import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Pinia store for wanted-card friend matching.
 *
 * Replaces the per-tab useWantedMatches composable. Lives at the deck
 * (route) level so any consumer — PhysicalCopiesTab, DeckDetailSidebar,
 * WantedMatchSummaryTab — can read the same matches without provide/inject.
 *
 * Lifecycle is owned by DeckView: it calls fetch() when the deck route id
 * changes and reset() on unmount.
 */
export const useWantedMatchesStore = defineStore('wantedMatches', {
  state: () => ({
    /** Array of { scryfall_card_id, card_name, wanted_quantity, friends[] }. */
    matches: [],
    loading: false,
    error: null,
    /** Total accepted-friend count. null = not yet loaded. */
    friendCount: null,
    /** True when a friend.visibility_changed notification arrived mid-session. */
    visibilityRevoked: false,
    /** Active match for the side panel; null when the panel is closed. */
    activeMatch: null,
  }),

  getters: {
    matchByCardId: (state) => {
      const map = {}
      for (const m of state.matches) map[m.scryfall_card_id] = m
      return map
    },

    /**
     * Returns the match entry for a scryfall_card_id, or null when the card
     * has no entry in the response.
     */
    matchFor: (state) => (scryfallCardId) =>
      state.matches.find((m) => m.scryfall_card_id === scryfallCardId) ?? null,

    /**
     * True when the user has friends but none have shared their collections.
     * Inferred when friendCount > 0 and every match entry has zero friends.
     */
    noVisibleFriends: (state) => {
      if (state.loading || state.error) return false
      const c = state.friendCount
      if (c === null || c === 0) return false
      return state.matches.every((m) => m.friends.length === 0)
    },
  },

  actions: {
    async fetch(deckId) {
      if (!deckId || this.loading) return
      this.loading = true
      this.error = null
      this.visibilityRevoked = false
      try {
        const [matchRes, friendRes] = await Promise.allSettled([
          api.get(`/decks/${deckId}/wanted-matches`),
          api.get('/friends'),
        ])

        if (matchRes.status === 'fulfilled') {
          // Controller returns { "data": [...] }. Accept the bare-array
          // shape too so the mock fixtures (which store the array directly)
          // keep working.
          const body = matchRes.value.data
          this.matches = Array.isArray(body)
            ? body
            : Array.isArray(body?.data) ? body.data : []
        } else {
          const e = matchRes.reason
          this.error =
            e.response?.data?.message ||
            'Failed to load friend matches. Try again later.'
          this.matches = []
        }

        if (friendRes.status === 'fulfilled') {
          const friendData = friendRes.value.data
          // /friends returns either a bare array (older shape) or { data: [...] }.
          const list = Array.isArray(friendData)
            ? friendData
            : Array.isArray(friendData?.data) ? friendData.data : []
          this.friendCount = list.length
        } else {
          this.friendCount = null
        }
      } finally {
        this.loading = false
      }
    },

    markVisibilityRevoked() {
      this.visibilityRevoked = true
    },

    /**
     * Open the side panel for a card. Accepts either a raw match entry
     * (from WantedMatchSummaryTab) or a deck entry (from row clicks). For
     * deck entries with no match in the response, opens with an empty
     * friends list so the panel can render the appropriate empty state.
     */
    openPanel(matchOrEntry) {
      if (!matchOrEntry) return
      if ('scryfall_card_id' in matchOrEntry) {
        this.activeMatch = matchOrEntry
        return
      }
      const match = this.matchFor(matchOrEntry.scryfall_id)
      this.activeMatch = match ?? {
        scryfall_card_id: matchOrEntry.scryfall_id,
        card_name: matchOrEntry.scryfall_card?.name ?? '',
        wanted_quantity: matchOrEntry.wanted_quantity ?? 1,
        friends: [],
      }
    },

    closePanel() {
      this.activeMatch = null
    },

    reset() {
      this.matches = []
      this.loading = false
      this.error = null
      this.friendCount = null
      this.visibilityRevoked = false
      this.activeMatch = null
    },
  },
})
