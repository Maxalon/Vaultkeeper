/**
 * useWantedMatches — fetches and caches wanted-card match data for a deck.
 *
 * Wraps GET /decks/{deckId}/wanted-matches. The result is an array of:
 *   { scryfall_card_id, card_name, wanted_quantity, friends: [...] }
 *
 * One instance per deck view. The composable is intentionally side-effect
 * free on import — call `fetch()` explicitly after the deck has loaded.
 *
 * The "no reservation system" warning lives in the UI layer
 * (WantedMatchPanel, WantedMatchAvatarStack). This composable has no
 * opinion on it.
 */

import { ref, computed } from 'vue'
import api from '../lib/api'

export function useWantedMatches() {
  const matches = ref([])
  const loading = ref(false)
  const error = ref(null)

  /**
   * Keyed lookup: scryfall_card_id → match entry.
   * Used by DeckGrid rows to pull the friend list for a single card cheaply.
   */
  const matchByCardId = computed(() => {
    const map = {}
    for (const m of matches.value) {
      map[m.scryfall_card_id] = m
    }
    return map
  })

  /**
   * Returns the match entry for a given scryfall_card_id, or null if the
   * card is not in the wanted-matches response (i.e. it has no wanted entry
   * or no friend matches exist yet).
   */
  function matchFor(scryfallCardId) {
    return matchByCardId.value[scryfallCardId] ?? null
  }

  /**
   * Fetch (or re-fetch) wanted matches for `deckId`.
   * Calling this while a fetch is already in flight is a no-op.
   */
  async function fetch(deckId) {
    if (!deckId || loading.value) return
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get(`/decks/${deckId}/wanted-matches`)
      matches.value = Array.isArray(data) ? data : []
    } catch (e) {
      error.value =
        e.response?.data?.message ||
        'Failed to load friend matches. Try again later.'
      matches.value = []
    } finally {
      loading.value = false
    }
  }

  /** Clear all state — call on deck unmount / deck change. */
  function reset() {
    matches.value = []
    loading.value = false
    error.value = null
  }

  return {
    matches,
    loading,
    error,
    matchFor,
    fetch,
    reset,
  }
}
