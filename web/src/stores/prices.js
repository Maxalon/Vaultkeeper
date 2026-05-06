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

    async fetchCollectionTotals() {
      this.loadingCollection = true
      try {
        const { data } = await api.get('/collection/totals')
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
  },
})
