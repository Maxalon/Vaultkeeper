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
/**
 * Color sort key for WUBRG ordering. Mono colors first (W, U, B, R, G),
 * then multicolor by count, then colorless last.
 */
const WUBRG = { W: 0, U: 1, B: 2, R: 3, G: 4 }
function colorSortKey(colors) {
  if (!colors || colors.length === 0) return '9' // colorless last
  if (colors.length === 1) return '0' + WUBRG[colors[0]]
  // Multi: prefix with count so 2-color < 3-color, then WUBRG positions
  return '' + colors.length + [...colors].sort((a, b) => WUBRG[a] - WUBRG[b]).map(c => WUBRG[c]).join('')
}

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

function loadCollapsedGroups() {
  try {
    return JSON.parse(localStorage.getItem('vaultkeeper-group-collapse') || '{}')
  } catch {
    return {}
  }
}

export const useCollectionStore = defineStore('collection', {
  state: () => ({
    /**
     * Top-level sidebar structure: a single array interleaving groups and
     * un-grouped locations. Each item has `kind: 'group' | 'location'`.
     * Groups carry their nested locations in `item.locations`.
     */
    sidebarItems: [],
    collapsedGroups: loadCollapsedGroups(),
    totalCount: 0,
    activeLocationId: null, // number | null (null = all cards)
    entries: [],
    activeEntry: null, // full detail object for the current sidebar
    activeEntryId: null,
    selectedIds: [],
    selecting: false,
    loading: false,
    detailLoading: false,
    error: null,
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
    /**
     * Flat array of every location, in render order. Used by location
     * dropdowns in CardListPanel, ImportModal, and DetailSidebar — they
     * don't care about grouping.
     */
    locations(state) {
      return state.sidebarItems.flatMap((item) =>
        item.kind === 'group' ? item.locations : [item],
      )
    },

    /** Groups extracted from sidebarItems in their current order. */
    groups(state) {
      return state.sidebarItems.filter((item) => item.kind === 'group')
    },

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
      let result = tokens.length === 0
        ? state.entries
        : state.entries.filter((e) => entryMatchesTokens(e, tokens))

      if (state.filters.sort === 'color') {
        const dir = state.filters.order === 'desc' ? -1 : 1
        result = [...result].sort((a, b) => {
          const ka = colorSortKey(a.card?.colors)
          const kb = colorSortKey(b.card?.colors)
          if (ka !== kb) return ka < kb ? -dir : dir
          // Secondary: alphabetical by name
          const na = (a.card?.name || '').toLowerCase()
          const nb = (b.card?.name || '').toLowerCase()
          return na < nb ? -1 : na > nb ? 1 : 0
        })
      }

      return result
    },
  },

  actions: {
    setDisplayMode(mode) {
      if (mode === 'A' || mode === 'B') this.displayMode = mode
    },

    async fetchGroups() {
      const { data } = await api.get('/location-groups')
      this.sidebarItems = data.items
      this.totalCount = data.total_count
    },

    // Alias so existing callers (createLocation, updateEntry, batchMove,
    // CollectionView initial load, ImportModal) keep working unchanged.
    async fetchLocations() {
      await this.fetchGroups()
    },

    async createGroup(name) {
      this.loading = true
      try {
        await api.post('/location-groups', { name })
        await this.fetchGroups()
      } finally {
        this.loading = false
      }
    },

    async updateGroup(id, name) {
      this.loading = true
      try {
        await api.put(`/location-groups/${id}`, { name })
        await this.fetchGroups()
      } finally {
        this.loading = false
      }
    },

    async deleteGroup(id) {
      this.loading = true
      try {
        await api.delete(`/location-groups/${id}`)
        if (this.collapsedGroups[id] !== undefined) {
          delete this.collapsedGroups[id]
          localStorage.setItem(
            'vaultkeeper-group-collapse',
            JSON.stringify(this.collapsedGroups),
          )
        }
        await this.fetchGroups()
      } finally {
        this.loading = false
      }
    },

    /**
     * Persist the full drag-and-drop state. Fires after vuedraggable has
     * already mutated the reactive arrays, so the UI is already correct —
     * we just need the server to match. On failure, surface the error and
     * refetch to snap back.
     */
    async reorderAll() {
      const payload = {
        items: this.sidebarItems.map((item) => {
          if (item.kind === 'group') {
            return {
              kind: 'group',
              id: item.id,
              location_ids: item.locations.map((l) => l.id),
            }
          }
          return { kind: 'location', id: item.id }
        }),
      }
      this.loading = true
      try {
        await api.post('/location-groups/reorder', payload)
      } catch (e) {
        this.error = e.response?.data?.message || 'Failed to reorder locations'
        await this.fetchGroups()
      } finally {
        this.loading = false
      }
    },

    toggleGroupCollapse(groupId) {
      this.collapsedGroups = {
        ...this.collapsedGroups,
        [groupId]: !this.isGroupCollapsed(groupId),
      }
      localStorage.setItem(
        'vaultkeeper-group-collapse',
        JSON.stringify(this.collapsedGroups),
      )
    },

    isGroupCollapsed(groupId) {
      return this.collapsedGroups[groupId] ?? true
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
        if (typeof this.activeLocationId === 'number') {
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
      const { data } = await api.post('/locations', payload)
      await this.fetchLocations()
      return data
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

    toggleSelecting() {
      this.selecting = !this.selecting
      if (!this.selecting) this.selectedIds = []
    },

    toggleSelect(id) {
      const idx = this.selectedIds.indexOf(id)
      if (idx === -1) this.selectedIds.push(id)
      else this.selectedIds.splice(idx, 1)
    },

    selectAll() {
      this.selectedIds = this.filteredEntries.map((e) => e.id)
    },

    clearSelection() {
      this.selectedIds = []
    },

    async batchMove(locationId) {
      if (!this.selectedIds.length) return
      this.loading = true
      try {
        await api.post('/collection/batch-move', {
          ids: [...this.selectedIds],
          location_id: locationId ?? null,
        })
        this.selectedIds = []
        this.selecting = false
        await Promise.all([this.fetchLocations(), this.fetchEntries()])
      } finally {
        this.loading = false
      }
    },

    async deleteEntry(id) {
      await api.delete(`/collection/${id}`)
      this.entries = this.entries.filter((e) => e.id !== id)
      if (this.activeEntryId === id) this.closeActiveEntry()
      this.fetchLocations()
    },
  },
})
