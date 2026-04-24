import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Module-local cancellation state. Kept outside Pinia state so it's not
 * reactive (an AbortController signal doesn't belong in a watch graph) and
 * so multiple store instances can't stomp each other's cancellations.
 */
let inflightController = null
let latestRequestId = 0

/**
 * Catalog store — Scryfall-syntax search against the local scryfall_cards
 * table. Independent of collection.js so the catalog can drop into the
 * DB-3 deckbuilder tab shell without cross-store coupling.
 *
 * State is ephemeral. Only display mode + card size persist to
 * localStorage; active card + printing selections reset on navigation.
 */

const DISPLAY_MODE_KEY = 'vaultkeeper-catalog-display-mode'
const CARD_SIZE_KEY = 'vaultkeeper-catalog-card-size'

function loadDisplayMode() {
  const v = localStorage.getItem(DISPLAY_MODE_KEY)
  return v === 'strip' ? 'strip' : 'grid'
}

function loadCardSize() {
  const v = localStorage.getItem(CARD_SIZE_KEY)
  return v === 'small' || v === 'large' ? v : 'medium'
}

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    // Query / results
    query: '',
    results: [],
    total: 0,
    page: 1,
    lastPage: 1,
    perPage: 60,
    loading: false,
    loadingMore: false,
    warnings: [],
    warningsDismissed: false,

    // Context props (set by caller — usually the mounting view)
    deckId: null,
    ownedOnly: false,

    // Deck-filter pills. When deckId is set, the backend applies both
    // filters by default; toggling a pill off sends apply_format=false /
    // apply_identity=false on the next search.
    deckFilters: {
      format: null,
      colorIdentity: null,
      formatActive: true,
      colorIdentityActive: true,
    },

    // Display settings (persisted)
    displayMode: loadDisplayMode(),   // 'grid' | 'strip'
    cardSize: loadCardSize(),         // 'small' | 'medium' | 'large'

    // Active card + printing state
    activeCardOracleId: null,
    activePrintings: {},              // oracle_id -> selected scryfall_id (catalog-local)
    printingsByOracle: {},             // oracle_id -> printings[]
    printingsLoading: {},              // oracle_id -> bool
  }),

  getters: {
    /** The chosen printing for a given oracle row, falling back to the
     *  representative the backend picked. */
    effectiveScryfallId: (state) => (row) =>
      state.activePrintings[row.oracle_id] || row.scryfall_id,

    activeCard: (state) =>
      state.activeCardOracleId
        ? state.results.find((r) => r.oracle_id === state.activeCardOracleId) || null
        : null,

    /** True when the deck-view catalog has enough pre-applied filters to
     *  show useful results without any user-typed query. Requires the
     *  format-legality pill plus at least one more discriminating filter
     *  (color identity for Commander/Oathbreaker, or "owned only"). When
     *  active, the panel runs an empty-q search sorted by edhrec_rank so
     *  the user lands on a populated, popularity-ranked list. */
    isAutoSearchContext: (state) => {
      if (!state.deckId) return false
      if (!state.deckFilters.format || !state.deckFilters.formatActive) return false
      const hasIdentity =
        state.deckFilters.colorIdentity !== null && state.deckFilters.colorIdentityActive
      return hasIdentity || state.ownedOnly
    },
  },

  actions: {
    /**
     * Build the query params object. Centralised so search() and loadMore()
     * stay in sync as filters evolve.
     */
    buildParams(page) {
      // When the user hasn't typed anything but the deck-view filters are
      // discriminating enough to stand on their own (see isAutoSearchContext),
      // send an `order:edhrec` directive so the unfiltered tail is sorted by
      // popularity instead of alphabetically. The backend's sort extractor
      // pulls this out of `q` before applying WHERE clauses, so the empty
      // query still produces no extra constraints.
      const trimmed = (this.query || '').trim()
      const q = trimmed === '' && this.isAutoSearchContext ? 'order:edhrec' : this.query
      const params = { q, per_page: this.perPage, page }
      if (this.deckId) {
        params.deck_id = this.deckId
        // Only send overrides when the user has actually toggled a pill off.
        if (!this.deckFilters.formatActive) params.apply_format = 0
        if (!this.deckFilters.colorIdentityActive) params.apply_identity = 0
      }
      if (this.ownedOnly) params.owned_only = 1
      return params
    },

    async search(query) {
      if (typeof query === 'string') this.query = query
      this.page = 1
      this.warningsDismissed = false

      // Cancel any still-running search. PHP-FPM has a tight worker pool;
      // letting a cascade of typed searches all hit the backend will saturate
      // it and every subsequent request will hang behind the queue.
      if (inflightController) inflightController.abort()
      inflightController = new AbortController()
      const controller = inflightController

      // Monotonic ID so late responses from stale requests (that finished
      // after we already moved on) are dropped instead of clobbering state.
      const reqId = ++latestRequestId

      this.loading = true
      try {
        const { data } = await api.get('/scryfall-cards/search', {
          params: this.buildParams(1),
          signal: controller.signal,
        })
        if (reqId !== latestRequestId) return
        this.results = data.data
        this.total = data.total
        this.lastPage = data.last_page
        this.perPage = data.per_page
        this.warnings = data.warnings || []
      } catch (e) {
        // Axios surfaces both AbortError (native) and CanceledError (axios).
        if (e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
        throw e
      } finally {
        // Only the request we're tracking flips loading off — otherwise a
        // cancelled request's finally could clear "loading" that the newer
        // in-flight request just set.
        if (reqId === latestRequestId) this.loading = false
      }
    },

    async loadMore() {
      if (this.loadingMore || this.page >= this.lastPage) return
      // Capture the page snapshot when we fired so a racing search() that
      // advances to page 1 doesn't leave us appending page-2 of a now-stale
      // result set.
      const reqId = latestRequestId
      this.loadingMore = true
      try {
        const nextPage = this.page + 1
        const { data } = await api.get('/scryfall-cards/search', {
          params: this.buildParams(nextPage),
        })
        if (reqId !== latestRequestId) return
        this.results.push(...data.data)
        this.page = nextPage
        this.lastPage = data.last_page
      } finally {
        this.loadingMore = false
      }
    },

    /** Open the detail sidebar for an oracle row. Triggers printings fetch
     *  if not already cached. */
    setActiveCard(oracleId) {
      this.activeCardOracleId = oracleId
      if (oracleId && !this.printingsByOracle[oracleId]) {
        this.fetchPrintings(oracleId)
      }
    },

    clearActive() {
      this.activeCardOracleId = null
    },

    async fetchPrintings(oracleId) {
      this.printingsLoading = { ...this.printingsLoading, [oracleId]: true }
      try {
        const { data } = await api.get('/scryfall-cards/printings', {
          params: { oracle_id: oracleId },
        })
        this.printingsByOracle = { ...this.printingsByOracle, [oracleId]: data.data }
      } finally {
        this.printingsLoading = { ...this.printingsLoading, [oracleId]: false }
      }
    },

    pickPrinting(oracleId, scryfallId) {
      this.activePrintings = { ...this.activePrintings, [oracleId]: scryfallId }
    },

    toggleDeckFilter(which) {
      const key = which === 'format' ? 'formatActive' : 'colorIdentityActive'
      this.deckFilters[key] = !this.deckFilters[key]
      this.refreshFromContext()
    },

    toggleOwnedOnly() {
      this.ownedOnly = !this.ownedOnly
      this.refreshFromContext()
    },

    /** Re-fire the search after a context change (filter pill, ownedOnly,
     *  deck swap). Honors the same gating as the input watcher: a typed
     *  query (>=2 chars) always re-searches; an empty query re-searches
     *  only if the deck-view auto-search conditions are met; otherwise
     *  results are cleared so the UI doesn't keep showing stale rows. */
    refreshFromContext() {
      const trimmed = (this.query || '').trim()
      if (trimmed.length >= 2 || (trimmed.length === 0 && this.isAutoSearchContext)) {
        this.search(this.query)
        return
      }
      this.results = []
      this.total = 0
      this.warnings = []
    },

    setDisplayMode(mode) {
      this.displayMode = mode === 'strip' ? 'strip' : 'grid'
      localStorage.setItem(DISPLAY_MODE_KEY, this.displayMode)
    },

    setCardSize(size) {
      this.cardSize = ['small', 'medium', 'large'].includes(size) ? size : 'medium'
      localStorage.setItem(CARD_SIZE_KEY, this.cardSize)
    },

    dismissWarnings() {
      this.warningsDismissed = true
    },

    /** Set the deckId context (from parent view); stores the deck's format +
     *  identity so filter pills have labels. Called both on mount and
     *  whenever the deck's commanders / format change (commander swap
     *  recomputes the color identity on the backend, which flows back in).
     *
     *  Preserves the user's toggle state (formatActive, colorIdentityActive)
     *  across same-deck re-syncs — swapping a commander shouldn't re-enable
     *  a filter the user just turned off. Only resets when the deck itself
     *  changes (different deckId or going from null → a deck). */
    setDeckContext({ deckId, format, colorIdentity }) {
      const nextId = deckId || null
      const deckChanged = this.deckId !== nextId
      this.deckId = nextId
      this.deckFilters.format = format || null
      this.deckFilters.colorIdentity = colorIdentity ?? null
      if (deckChanged) {
        this.deckFilters.formatActive = true
        this.deckFilters.colorIdentityActive = true
      }
    },
  },
})
