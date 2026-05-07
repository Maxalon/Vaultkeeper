import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Tiny store for pricing metadata + collection/deck totals. The
 * per-card prices ride on the existing card payloads (catalog,
 * collection list, detail sidebar) — this store only owns the
 * top-level "last updated" hint and the aggregate totals.
 */
export const usePricesStore = defineStore('prices', {
  state: () => ({
    lastSyncedAt: null,
    lastManifestAt: null,
    statusFetchedAt: 0,
    collection: null, // { total, card_count, missing_price_count, generated_at }
    deckTotalsByDeckId: {}, // deckId -> { total, owned_total, missing_total, missing_price_count }
    loadingStatus: false,
    loadingCollection: false,
  }),

  getters: {
    isStatusKnown: (state) => state.lastSyncedAt !== null,
  },

  actions: {
    /**
     * Fetch /api/prices/status. Cached for the session — there's no
     * point hitting the endpoint more than once per ~hour since the
     * underlying job runs daily.
     */
    async fetchStatus({ force = false } = {}) {
      if (!force && this.statusFetchedAt > 0
          && Date.now() - this.statusFetchedAt < 60 * 60 * 1000) {
        return
      }
      this.loadingStatus = true
      try {
        const { data } = await api.get('/prices/status')
        this.lastSyncedAt = data.last_synced_at
        this.lastManifestAt = data.last_manifest_at
        this.statusFetchedAt = Date.now()
      } finally {
        this.loadingStatus = false
      }
    },

    /**
     * Fetch /api/collection/totals, optionally scoped to a location.
     *
     *   - locationId omitted / undefined → "all cards" total
     *   - locationId === null            → "unassigned" bucket
     *   - locationId === <number>        → that specific location
     *
     * Mirrors the location_id filter shape on /api/collection so the
     * total tracks whatever the user is currently looking at.
     */
    async fetchCollectionTotals(locationId) {
      this.loadingCollection = true
      try {
        const params = {}
        if (arguments.length >= 1) {
          params.location_id = locationId === null ? 'unassigned' : locationId
        }
        const { data } = await api.get('/collection/totals', { params })
        this.collection = data
        return data
      } finally {
        this.loadingCollection = false
      }
    },

    async fetchDeckTotals(deckId) {
      const { data } = await api.get(`/decks/${deckId}/totals`)
      this.deckTotalsByDeckId = { ...this.deckTotalsByDeckId, [deckId]: data }
      return data
    },

    invalidateCollection() {
      this.collection = null
    },

    invalidateDeck(deckId) {
      if (deckId in this.deckTotalsByDeckId) {
        const { [deckId]: _, ...rest } = this.deckTotalsByDeckId
        this.deckTotalsByDeckId = rest
      }
    },

    /**
     * Debounced post-mutation refresh. Call from any store action that
     * touches data feeding the totals so the StatsBar / deck-header
     * pills don't drift from the underlying state.
     *
     *   { collectionLocation: null | number | 'unassigned' }
     *     → refresh /api/collection/totals for that scope
     *   { deckId: number }
     *     → refresh /api/decks/{deckId}/totals
     *
     * Bursts of rapid mutations (e.g. four +1s) collapse into one fetch
     * per scope per 250ms window. Per-scope timers so unrelated scopes
     * don't cancel each other.
     */
    scheduleRefresh({ collectionLocation, deckId } = {}) {
      if (!this._refreshTimers) this._refreshTimers = {}

      if (collectionLocation !== undefined) {
        const key = `c:${collectionLocation === null ? 'all' : collectionLocation}`
        if (this._refreshTimers[key]) clearTimeout(this._refreshTimers[key])
        this._refreshTimers[key] = setTimeout(() => {
          delete this._refreshTimers[key]
          // Mirror the call shape of CollectionView's refreshTotals: null
          // gets the all-cards endpoint, anything else is forwarded as-is.
          if (collectionLocation === null) {
            this.fetchCollectionTotals().catch(() => {})
          } else {
            this.fetchCollectionTotals(collectionLocation).catch(() => {})
          }
        }, 250)
      }

      if (deckId != null) {
        const key = `d:${deckId}`
        if (this._refreshTimers[key]) clearTimeout(this._refreshTimers[key])
        this._refreshTimers[key] = setTimeout(() => {
          delete this._refreshTimers[key]
          this.fetchDeckTotals(deckId).catch(() => {})
        }, 250)
      }
    },
  },
})
