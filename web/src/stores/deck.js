import { defineStore } from 'pinia'
import api from '../lib/api'
import { useToast } from '../composables/useToast'

/**
 * Illegality classification — card-level illegalities glow on the card;
 * deck-level glow on the deck name. See DB-3 plan decision 15.
 */
const DECK_LEVEL_ILLEGALITY_TYPES = new Set([
  'deck_size',
  'too_many_cards',
  'invalid_commander',
  'invalid_partner',
])

export const useDeckStore = defineStore('deck', {
  state: () => ({
    deck: null,
    entries: [],
    /** Map of scryfall_id → CollectionEntry[] for the physical-copy dropdown */
    ownedCopiesByScryfallId: {},
    illegalities: [],
    loading: false,
    /** Set of entry ids currently mid-patch — for spinners on in-flight rows */
    saving: new Set(),
    activeEntryId: null,
    /**
     * View state survives undock/redock. Persisted per-deck in localStorage
     * under `vaultkeeper_deck_view_{deckId}` by DeckFilterBar.
     */
    view: {
      search: '',
      groupBy: 'categories',
      sort: 'name',
      displayMode: 'strips',
    },
    /** Undock flags for side/maybe sections. */
    sideUndocked: false,
    maybeUndocked: false,
  }),

  getters: {
    entriesByZone: (state) => (zone) =>
      state.entries.filter((e) => e.zone === zone),

    commanderEntries: (state) =>
      state.entries.filter((e) => e.is_commander),

    signatureSpellEntries: (state) =>
      state.entries.filter((e) => e.is_signature_spell),

    companionEntry: (state) => {
      if (!state.deck?.companion_scryfall_id) return null
      return state.entries.find(
        (e) => e.scryfall_id === state.deck.companion_scryfall_id,
      ) || null
    },

    categoriesInDeck: (state) => {
      const set = new Set()
      for (const e of state.entries) {
        if (e.zone === 'main' && e.category) set.add(e.category)
      }
      return [...set].sort()
    },

    activeIllegalities: (state) =>
      state.illegalities.filter((i) => !i.ignored),

    /**
     * Map: scryfall_id → array of illegalities that should shine that card.
     * Deck-level illegalities are excluded — they glow on the deck name.
     */
    cardLevelIllegalitiesByScryfallId: (state) => {
      const map = {}
      for (const ill of state.illegalities) {
        if (ill.ignored) continue
        if (DECK_LEVEL_ILLEGALITY_TYPES.has(ill.type)) continue
        const id = ill.scryfall_id_1
        if (!id) continue
        if (!map[id]) map[id] = []
        map[id].push(ill)
      }
      return map
    },

    deckLevelIllegalities: (state) =>
      state.illegalities.filter(
        (i) => !i.ignored && DECK_LEVEL_ILLEGALITY_TYPES.has(i.type),
      ),

    hasDeckLevelIllegality() {
      return this.deckLevelIllegalities.length > 0
    },
  },

  actions: {
    async loadDeck(id) {
      this.loading = true
      try {
        const { data } = await api.get(`/decks/${id}`)
        this.deck = data
      } finally {
        this.loading = false
      }
    },

    async loadEntries(id) {
      const { data } = await api.get(`/decks/${id}/entries`, {
        params: { sort: 'name' },
      })
      this.entries = data
    },

    async loadIllegalities(id) {
      const { data } = await api.get(`/decks/${id}/illegalities`)
      this.illegalities = data
    },

    async loadOwnedCopies(scryfallId) {
      if (!scryfallId) return
      const { data } = await api.get('/collection/copies', {
        params: { scryfall_id: scryfallId },
      })
      this.ownedCopiesByScryfallId = {
        ...this.ownedCopiesByScryfallId,
        [scryfallId]: data,
      }
      return data
    },

    async addEntry(deckId, payload) {
      const toast = useToast()
      try {
        const { data } = await api.post(`/decks/${deckId}/entries`, payload)
        this.entries.push(data)
        await this.loadIllegalities(deckId)
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to add card')
        throw e
      }
    },

    async updateEntry(deckId, entryId, patch) {
      const toast = useToast()
      const idx = this.entries.findIndex((e) => e.id === entryId)
      if (idx === -1) return
      const prev = { ...this.entries[idx] }
      this.entries[idx] = { ...prev, ...patch }
      this.saving.add(entryId)
      try {
        const { data } = await api.patch(
          `/decks/${deckId}/entries/${entryId}`,
          patch,
        )
        // Reconcile with server-authoritative response (keep derived fields)
        this.entries[idx] = { ...this.entries[idx], ...data }
        await this.loadIllegalities(deckId)
        return data
      } catch (e) {
        this.entries[idx] = prev
        toast.error(e.response?.data?.message || 'Update failed')
        throw e
      } finally {
        this.saving.delete(entryId)
      }
    },

    async moveEntryZone(deckId, entryId, zone) {
      return this.updateEntry(deckId, entryId, { zone })
    },

    async removeEntry(deckId, entryId) {
      const toast = useToast()
      const idx = this.entries.findIndex((e) => e.id === entryId)
      if (idx === -1) return
      const removed = this.entries.splice(idx, 1)[0]
      if (this.activeEntryId === entryId) this.activeEntryId = null
      try {
        await api.delete(`/decks/${deckId}/entries/${entryId}`)
        await this.loadIllegalities(deckId)
      } catch (e) {
        this.entries.splice(idx, 0, removed)
        toast.error(e.response?.data?.message || 'Delete failed')
        throw e
      }
    },

    async updateDeck(id, patch) {
      const toast = useToast()
      try {
        const { data } = await api.put(`/decks/${id}`, patch)
        this.deck = data
        await this.loadIllegalities(id)
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Deck update failed')
        throw e
      }
    },

    async setCompanion(id, scryfallId) {
      return this.updateDeck(id, { companion_scryfall_id: scryfallId })
    },

    async setCommander(slot, scryfallId) {
      if (!this.deck) return
      const field = slot === 2 ? 'commander_2_scryfall_id' : 'commander_1_scryfall_id'
      return this.updateDeck(this.deck.id, { [field]: scryfallId })
    },

    async ignoreIllegality(id, payload) {
      await api.post(`/decks/${id}/illegalities/ignore`, payload)
      await this.loadIllegalities(id)
    },

    async unignoreIllegality(id, payload) {
      const body = { ...payload }
      delete body.expected_count
      await api.post(`/decks/${id}/illegalities/unignore`, body)
      await this.loadIllegalities(id)
    },

    setActiveEntry(entryId) {
      this.activeEntryId = this.activeEntryId === entryId ? null : entryId
    },

    setUndocked(section, value) {
      if (section === 'side')  this.sideUndocked  = !!value
      if (section === 'maybe') this.maybeUndocked = !!value
    },

    reset() {
      this.deck = null
      this.entries = []
      this.ownedCopiesByScryfallId = {}
      this.illegalities = []
      this.activeEntryId = null
      this.saving = new Set()
      this.sideUndocked = false
      this.maybeUndocked = false
    },
  },
})
