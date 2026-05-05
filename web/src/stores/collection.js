import { defineStore } from 'pinia'
import api from '../lib/api'
import {
  parseSearch as parseSearchGeneric,
  serializeQuery as serializeQueryGeneric,
} from '../lib/searchQuery'

/**
 * Chip keys drive the topbar dropdowns; aliases let the long forms
 * (`type:`, `color:`, `rarity:`, `set:`) round-trip through the chips.
 * Filtering itself runs on the backend through CardSearchService, so the
 * full Scryfall syntax (is:, o:, ci:, etc.) is supported regardless of
 * what's listed here — these only matter for chip recognition.
 */
export const COLLECTION_SCHEMA = {
  chipKeys: ['c', 't', 'r', 's'],
  directiveKeys: ['sort', 'order'],
  aliases: { set: 's', type: 't', color: 'c', rarity: 'r' },
}

const COLLECTION_DEFAULTS = { sort: 'name' }

export function parseSearch(search) {
  const parsed = parseSearchGeneric(search, COLLECTION_SCHEMA)
  return {
    tokens: parsed.tokens,
    nameQuery: parsed.nameQuery,
    sort: parsed.directives.sort || parsed.directives.order || '',
    chips: parsed.chips,
  }
}

export function serializeQuery(state) {
  // Legacy state shape: { free, chips, sort }. Translate to the generic
  // directive bag before delegating.
  const adapted = {
    free: state.free,
    chips: state.chips,
    directives: { sort: state.sort || '' },
  }
  return serializeQueryGeneric(adapted, COLLECTION_SCHEMA, COLLECTION_DEFAULTS)
}

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
    /** Full decks list (flat — already split across groups by group_id). */
    decks: [],
    /**
     * Review-queue summary: { card_count } when there are review-flagged
     * copies, otherwise null. Driven by the backend so the row only
     * shows up when there's something in it.
     */
    review: null,
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
    filters: {
      sort: 'name',
      order: 'asc',
      search: '',
    },
    /** Warnings emitted by the backend search parser (unsupported ops, etc.). */
    searchWarnings: [],
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

    /**
     * Sidebar items with each deck merged into its group.locations list (or
     * appearing at the top level when group_id is null), interleaved by
     * sort_order. Decks carry `kind: 'deck'`. This is the render-time list.
     */
    sidebarItemsMerged(state) {
      const decksByGroup = new Map()
      const topLevelDecks = []
      for (const d of state.decks) {
        const item = { ...d, kind: 'deck' }
        if (d.group_id) {
          if (!decksByGroup.has(d.group_id)) decksByGroup.set(d.group_id, [])
          decksByGroup.get(d.group_id).push(item)
        } else {
          topLevelDecks.push(item)
        }
      }

      const merged = state.sidebarItems.map((item) => {
        if (item.kind !== 'group') return item
        const groupDecks = decksByGroup.get(item.id) || []
        if (!groupDecks.length) return item
        const combined = [...item.locations, ...groupDecks]
          .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
        return { ...item, locations: combined }
      })

      if (topLevelDecks.length) {
        return [...merged, ...topLevelDecks]
      }
      return merged
    },

    parsedSearch(state) {
      return parseSearch(state.filters.search)
    },
    /**
     * Backend handles all token filtering now (full Scryfall syntax via
     * CardSearchService). This getter only adds the client-side `color`
     * sort, which has no SQL-side equivalent — every other sort field
     * is handled in the SQL ORDER BY.
     */
    filteredEntries(state) {
      if (state.filters.sort !== 'color') return state.entries
      const dir = state.filters.order === 'desc' ? -1 : 1
      return [...state.entries].sort((a, b) => {
        const ka = colorSortKey(a.card?.colors)
        const kb = colorSortKey(b.card?.colors)
        if (ka !== kb) return ka < kb ? -dir : dir
        const na = (a.card?.name || '').toLowerCase()
        const nb = (b.card?.name || '').toLowerCase()
        return na < nb ? -1 : na > nb ? 1 : 0
      })
    },
  },

  actions: {
    async fetchGroups() {
      const { data } = await api.get('/location-groups')
      this.sidebarItems = data.items
      this.totalCount = data.total_count
      this.decks = data.decks || []
      this.review = data.review || null
    },

    // Alias so existing callers (createLocation, updateEntry, batchMove,
    // CollectionView initial load, ImportModal) keep working unchanged.
    async fetchLocations() {
      await this.fetchGroups()
    },

    async fetchDecks() {
      // fetchGroups already includes decks in its response, but expose a
      // standalone action so DeckView can refresh the list after an edit
      // without re-fetching the entire sidebar payload.
      const { data } = await api.get('/decks')
      this.decks = data
    },

    async createDeck(payload) {
      const { data } = await api.post('/decks', payload)
      await this.fetchGroups()
      return data
    },

    async importDeck(payload) {
      const { data } = await api.post('/decks/import', payload)
      await this.fetchGroups()
      return data
    },

    async importDeckCsv(formData) {
      const { data } = await api.post('/decks/import/csv', formData)
      await this.fetchGroups()
      return data
    },

    /**
     * Review-queue actions. The sidebar's `review` summary arrives via
     * fetchGroups; these add the full-list / resolve surface that
     * powers the /review route and the per-deck tab.
     *
     * @param {{ deckId?: number, reason?: string }} [opts] — `deckId`
     *   scopes the fetch to copies that came from a specific deck;
     *   `reason` filters by review_reason.
     */
    async fetchReviewList(opts = {}) {
      const params = {}
      if (opts.deckId) params.deck_id = opts.deckId
      if (opts.reason) params.reason  = opts.reason
      const { data } = await api.get('/review', { params })
      return data?.data || []
    },

    /**
     * @param {Array<{collection_entry_id:number,target_location_id?:number|null,discard?:boolean,accept_defaults?:boolean,condition?:string,foil?:boolean}>} assignments
     */
    async resolveReview(assignments) {
      const { data } = await api.post('/review/resolve', { assignments })
      await this.fetchGroups()
      return data
    },

    async updateDeck(id, payload) {
      const { data } = await api.put(`/decks/${id}`, payload)
      await this.fetchGroups()
      return data
    },

    async deleteDeck(id) {
      await api.delete(`/decks/${id}`)
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
     * Persist the full drag-and-drop state. Fires after vue-draggable-plus has
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
        // Full search string goes to the backend — CardSearchService parses
        // the Scryfall syntax there, same pipeline as the catalog.
        const q = (this.filters.search || '').trim()
        if (q) params.q = q
        if (typeof this.activeLocationId === 'number') {
          params.location_id = this.activeLocationId
        }
        const { data } = await api.get('/collection', { params })
        this.entries = data.data || []
        this.searchWarnings = data.warnings || []
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

    async deleteLocation(id, { deleteEntries = false } = {}) {
      await api.delete(`/locations/${id}`, {
        params: deleteEntries ? { delete_entries: 1 } : {},
      })
      if (this.activeLocationId === id) {
        this.activeLocationId = null
        this.entries = []
      }
      // Re-pull cards too when entries were dropped, so the All Cards view
      // and totals reflect the deletion.
      if (deleteEntries) {
        await this.fetchEntries()
      }
      await this.fetchLocations()
    },

    /**
     * Per-CE-id promise chain. Every CE-mutating action awaits the
     * previous in-flight one for the same id before kicking off, so a
     * burst of clicks (4 +1s in a row) can't interleave at the network
     * layer and step on each other's optimistic-locking version. Each
     * link includes a fresh version pulled from local state right
     * before the request fires.
     */
    _enqueueForEntry(id, fn) {
      if (!this._pendingByEntry) this._pendingByEntry = new Map()
      const prev = this._pendingByEntry.get(id) || Promise.resolve()
      const next = prev.catch(() => {}).then(fn)
      this._pendingByEntry.set(id, next)
      // Clean up the map entry once the chain settles to avoid leaking.
      next.finally(() => {
        if (this._pendingByEntry.get(id) === next) {
          this._pendingByEntry.delete(id)
        }
      })
      return next
    },

    async updateEntry(id, payload) {
      return this._enqueueForEntry(id, async () => {
        const local = this.entries.find((e) => e.id === id) ?? this.activeEntry
        const body = { ...payload }
        if (local && typeof local.version === 'number' && body.version === undefined) {
          body.version = local.version
        }
        const { data } = await api.patch(`/collection/${id}`, body)
        const idx = this.entries.findIndex((e) => e.id === id)
        if (idx !== -1) {
          this.entries[idx] = {
            ...this.entries[idx],
            quantity: data.quantity,
            condition: data.condition,
            foil: data.foil,
            notes: data.notes,
            location_id: data.location_id,
            version: data.version,
          }
        }
        if (this.activeEntryId === id) {
          this.activeEntry = data
        }
        if (payload.location_id !== undefined) {
          this.fetchLocations()
        }
        return data
      })
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
      return this._enqueueForEntry(id, async () => {
        const local = this.entries.find((e) => e.id === id) ?? this.activeEntry
        const params = local && typeof local.version === 'number'
          ? { version: local.version }
          : undefined
        await api.delete(`/collection/${id}`, { params })
        this.entries = this.entries.filter((e) => e.id !== id)
        if (this.activeEntryId === id) this.closeActiveEntry()
        this.fetchLocations()
      })
    },
  },
})
