import { defineStore } from 'pinia'
import api from '../lib/api'
import { useCollectionStore } from './collection'

const POLL_INTERVAL_MS = 2000
const DISMISS_DELAY_MS = 5000

/**
 * Drives the bulk-import progress popup. A single job runs at a time —
 * `start()` queues the job server-side and then polls `/decks/import/bulk/{key}`
 * for status until it reaches a terminal state.
 *
 * The sidebar is intentionally NOT refreshed mid-run: we only call
 * `collection.fetchGroups()` once, after the job finishes, so the UI
 * doesn't flicker as each deck lands.
 */
export const useBulkImportStore = defineStore('bulkImport', {
  state: () => ({
    visible: false,
    jobKey: null,
    state: 'idle', // 'idle' | 'queued' | 'running' | 'done' | 'failed'
    message: '',
    total: 0,
    imported: 0,
    updated: 0,
    skipped: 0,
    failed: 0,
    warnings: [],
    _pollHandle: null,
    _dismissHandle: null,
  }),

  actions: {
    async start({ source, username, onDuplicate = 'skip' }) {
      // If a previous run finished and is still on screen, clear it so we
      // don't show stale results while the new job kicks off.
      this.dismiss()

      this.visible = true
      this.state = 'queued'
      this.message = `Starting bulk import from ${source}…`
      this.total = 0
      this.imported = 0
      this.updated = 0
      this.skipped = 0
      this.failed = 0
      this.warnings = []

      try {
        const { data } = await api.post('/decks/import/bulk', {
          source,
          username,
          on_duplicate: onDuplicate,
        })
        this.jobKey = data.job_key
        this.message = `Queued bulk import for ${data.username}…`
        this._poll()
      } catch (e) {
        this.state = 'failed'
        const errs = e.response?.data?.errors
        this.message = errs
          ? Object.values(errs).flat().join('; ')
          : (e.response?.data?.message || 'Failed to start bulk import')
        this._scheduleDismiss()
      }
    },

    _poll() {
      this._clearTimers()
      this._pollHandle = setInterval(() => this._tick(), POLL_INTERVAL_MS)
      // Kick once immediately so the user sees movement faster than the
      // first interval would allow.
      this._tick()
    },

    async _tick() {
      if (!this.jobKey) return
      try {
        const { data } = await api.get(`/decks/import/bulk/${this.jobKey}`)
        this.state = data.state
        this.message = data.message || ''
        this.total = data.total ?? 0
        this.imported = data.imported ?? 0
        this.updated = data.updated ?? 0
        this.skipped = data.skipped ?? 0
        this.failed = data.failed ?? 0
        if (Array.isArray(data.warnings)) this.warnings = data.warnings

        if (data.state === 'done' || data.state === 'failed') {
          this._clearTimers()
          if (data.state === 'done') {
            // Single sidebar refresh — the whole point of the queued flow.
            const collection = useCollectionStore()
            await collection.fetchGroups()
          }
          this._scheduleDismiss()
        }
      } catch (e) {
        // 404 means the cache entry expired; treat as a soft failure so the
        // popup doesn't get stuck in "running" forever.
        if (e.response?.status === 404) {
          this.state = 'failed'
          this.message = 'Lost track of the import job (cache expired).'
          this._clearTimers()
          this._scheduleDismiss()
        }
      }
    },

    _scheduleDismiss() {
      this._dismissHandle = setTimeout(() => this.dismiss(), DISMISS_DELAY_MS)
    },

    _clearTimers() {
      if (this._pollHandle) {
        clearInterval(this._pollHandle)
        this._pollHandle = null
      }
      if (this._dismissHandle) {
        clearTimeout(this._dismissHandle)
        this._dismissHandle = null
      }
    },

    dismiss() {
      this._clearTimers()
      this.visible = false
      this.jobKey = null
      this.state = 'idle'
      this.message = ''
      this.total = 0
      this.imported = 0
      this.updated = 0
      this.skipped = 0
      this.failed = 0
      this.warnings = []
    },
  },
})
