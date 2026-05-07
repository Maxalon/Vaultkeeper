/**
 * useWantedMatches — fetches and caches wanted-card match data for a deck.
 *
 * Wraps GET /decks/{deckId}/wanted-matches. The result is an array of:
 *   { scryfall_card_id, card_name, wanted_quantity, friends: [...] }
 *
 * One instance per deck view. The composable is intentionally side-effect
 * free on import — call `fetch()` explicitly after the deck has loaded.
 *
 * Friend-count context (C4)
 * ─────────────────────────
 * To distinguish the four empty states (0 friends, 0 visible friends,
 * 0 matches for a specific card, friend revoked mid-session), the
 * composable also fetches the total accepted friend count from
 * GET /friends alongside the wanted-matches request.
 *
 * `friendCount`        – total accepted friends (null = not yet loaded)
 * `visibilityRevoked`  – set to true when a `friend.visibility_changed`
 *                        notification is observed. Cleared on next fetch().
 *                        Call `markVisibilityRevoked()` from the notification
 *                        watcher in DeckTabContent, then re-fetch if needed.
 */

import { ref, computed } from 'vue'
import api from '../lib/api'

export function useWantedMatches() {
  const matches = ref([])
  const loading = ref(false)
  const error = ref(null)

  /**
   * Total accepted-friend count, fetched alongside wanted-matches.
   * `null` while not yet loaded; `0` means no friends at all.
   */
  const friendCount = ref(null)

  /**
   * Set when a `friend.visibility_changed` notification arrives while the
   * panel is open. The UI reads this to show a distinct "revoked visibility"
   * state rather than the generic "no matches" state.
   * Cleared automatically on the next fetch().
   */
  const visibilityRevoked = ref(false)

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
   * Also fetches total friend count for empty-state discrimination (C4).
   */
  async function fetch(deckId) {
    if (!deckId || loading.value) return
    loading.value = true
    error.value = null
    visibilityRevoked.value = false
    try {
      // Parallel: wanted-matches + friend count for empty-state context.
      const [matchRes, friendRes] = await Promise.allSettled([
        api.get(`/decks/${deckId}/wanted-matches`),
        api.get('/friends'),
      ])

      if (matchRes.status === 'fulfilled') {
        matches.value = Array.isArray(matchRes.value.data) ? matchRes.value.data : []
      } else {
        const e = matchRes.reason
        error.value =
          e.response?.data?.message ||
          'Failed to load friend matches. Try again later.'
        matches.value = []
      }

      if (friendRes.status === 'fulfilled') {
        const friendData = friendRes.value.data
        friendCount.value = Array.isArray(friendData) ? friendData.length : 0
      } else {
        // Non-fatal — omit friend count so we fall back gracefully.
        friendCount.value = null
      }
    } finally {
      loading.value = false
    }
  }

  /**
   * Signal that a friend's collection visibility was revoked mid-session.
   * Call this when a `friend.visibility_changed` notification arrives.
   * The UI will show a dedicated "visibility revoked" banner and you should
   * immediately call fetch() to refresh the list.
   */
  function markVisibilityRevoked() {
    visibilityRevoked.value = true
  }

  /** Clear all state — call on deck unmount / deck change. */
  function reset() {
    matches.value = []
    loading.value = false
    error.value = null
    friendCount.value = null
    visibilityRevoked.value = false
  }

  return {
    matches,
    loading,
    error,
    friendCount,
    visibilityRevoked,
    matchFor,
    fetch,
    markVisibilityRevoked,
    reset,
  }
}
