import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Parse a search string into client-side filter tokens (c:/r:/t:/s:) and
 * a residual name query that goes to the backend's `search` parameter.
 * Quoting and full Scryfall syntax (compound expressions, comparators) are
 * deliberately out of scope until a later session — this just makes the
 * dropdown-generated tokens functional.
 */
function parseSearch(search) {
  if (!search) return { tokens: [], nameQuery: '' }
  const tokens = []
  const nameParts = []
  for (const part of search.trim().split(/\s+/)) {
    const m = part.match(/^([crts]):(.+)$/i)
    if (m) {
      tokens.push({ type: m[1].toLowerCase(), value: m[2].toLowerCase() })
    } else if (part.length > 0) {
      nameParts.push(part)
    }
  }
  return { tokens, nameQuery: nameParts.join(' ') }
}

/**
 * Each token type independently AND'd against the card. Within a type the
 * semantics are intuitive for the dropdowns:
 *   c:w → card.colors includes 'W' (multiple c: tokens => all required)
 *   r:rare → card.rarity == 'rare'
 *   t:creature → type_line contains 'creature'
 *   s:fdn → card.set_code == 'FDN'
 */
function entryMatchesTokens(entry, tokens) {
  if (tokens.length === 0) return true
  const card = entry.card || {}
  return tokens.every((tok) => {
    switch (tok.type) {
      case 'c': {
        const colors = (card.colors || []).map((c) => c.toLowerCase())
        return colors.includes(tok.value)
      }
      case 'r':
        return (card.rarity || '').toLowerCase() === tok.value
      case 't':
        return (card.type_line || '').toLowerCase().includes(tok.value)
      case 's':
        return (card.set_code || '').toLowerCase() === tok.value
      default:
        return true
    }
  })
}

export const useCollectionStore = defineStore('collection', {
  state: () => ({
    locations: [],
    activeLocationId: null, // number | null | 'unassigned'
    entries: [],
    activeEntry: null, // full detail object for the current sidebar
    activeEntryId: null,
    loading: false,
    detailLoading: false,
    // 'A' = bar slides from top to bottom as image loads / strip expands
    // 'B' = corner quantity badge that slides into the bar on hover
    displayMode: 'A',
    filters: {
      sort: 'name',
      order: 'asc',
      search: '',
    },
  }),

  getters: {
    parsedSearch(state) {
      return parseSearch(state.filters.search)
    },
    /**
     * Entries with the parsed-out client-side filter tokens applied. The
     * card list panel iterates this instead of `entries` so dropdown
     * filters apply instantly without a backend round-trip.
     */
    filteredEntries(state) {
      const { tokens } = parseSearch(state.filters.search)
      if (tokens.length === 0) return state.entries
      return state.entries.filter((e) => entryMatchesTokens(e, tokens))
    },
  },

  actions: {
    setDisplayMode(mode) {
      if (mode === 'A' || mode === 'B') this.displayMode = mode
    },

    async fetchLocations() {
      const { data } = await api.get('/locations')
      this.locations = data
    },

    async fetchEntries() {
      this.loading = true
      try {
        const params = {
          sort: this.filters.sort,
          order: this.filters.order,
        }
        // Only the bare-name remainder hits the backend; client-side
        // filtering handles the dropdown tokens (see filteredEntries).
        const { nameQuery } = parseSearch(this.filters.search)
        if (nameQuery) params.search = nameQuery
        if (this.activeLocationId === 'unassigned') {
          params.location_id = 'unassigned'
        } else if (typeof this.activeLocationId === 'number') {
          params.location_id = this.activeLocationId
        }
        const { data } = await api.get('/collection', { params })
        this.entries = data
      } finally {
        this.loading = false
      }
    },

    async setActiveLocation(id) {
      this.activeLocationId = id
      this.activeEntryId = null
      this.activeEntry = null
      await this.fetchEntries()
    },

    async setActiveEntry(id) {
      if (this.activeEntryId === id) {
        // Toggle off when clicking the already-active strip.
        this.closeActiveEntry()
        return
      }
      this.activeEntryId = id
      this.detailLoading = true
      try {
        const { data } = await api.get(`/collection/${id}`)
        this.activeEntry = data
      } finally {
        this.detailLoading = false
      }
    },

    closeActiveEntry() {
      this.activeEntryId = null
      this.activeEntry = null
    },

    async createLocation(payload) {
      await api.post('/locations', payload)
      await this.fetchLocations()
    },

    async updateLocation(id, payload) {
      await api.put(`/locations/${id}`, payload)
      await this.fetchLocations()
    },

    async deleteLocation(id) {
      await api.delete(`/locations/${id}`)
      if (this.activeLocationId === id) {
        this.activeLocationId = null
        this.entries = []
      }
      await this.fetchLocations()
    },

    async updateEntry(id, payload) {
      const { data } = await api.patch(`/collection/${id}`, payload)
      const idx = this.entries.findIndex((e) => e.id === id)
      if (idx !== -1) {
        this.entries[idx] = {
          ...this.entries[idx],
          quantity: data.quantity,
          condition: data.condition,
          foil: data.foil,
          notes: data.notes,
          location_id: data.location_id,
        }
      }
      if (this.activeEntryId === id) {
        this.activeEntry = data
      }
      if (payload.location_id !== undefined) {
        this.fetchLocations()
      }
      return data
    },

    async deleteEntry(id) {
      await api.delete(`/collection/${id}`)
      this.entries = this.entries.filter((e) => e.id !== id)
      if (this.activeEntryId === id) this.closeActiveEntry()
      this.fetchLocations()
    },
  },
})
