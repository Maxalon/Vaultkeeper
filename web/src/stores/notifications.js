import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Pinia store for the centralized notifications table.
 *
 * Notifications are server-persisted and outlive page refreshes. Each
 * notification may carry declarative `actions[]` that the frontend renders
 * as buttons. Actions have an `available: bool` field — the server computes
 * staleness by checking whether the underlying model was mutated since the
 * notification was created.
 *
 * Action clicks POST to /notifications/{id}/actions/{key} (the server-side
 * gateway). The client never calls the underlying endpoint directly.
 *
 * This store is also the write target for useToast's `persist: true` option
 * (B7). In that case a notification is added optimistically to `items` before
 * the server round-trip completes, giving instant bell-badge feedback.
 */
export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    /** Paginated list of notification objects (most recent first). */
    items: [],
    /** Total unread count across all pages — drives the bell badge. */
    unreadCount: 0,
    /** Pagination meta from the last fetch. */
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    loading: false,
    actionLoading: null, // notification id whose action is in-flight, or null
    error: null,
  }),

  getters: {
    hasUnread: (state) => state.unreadCount > 0,

    /** Lookup a notification by id. */
    byId: (state) => (id) => state.items.find((n) => n.id === id) ?? null,
  },

  actions: {
    /**
     * Fetch (or refresh) the notification list.
     * @param {{ unreadOnly?: boolean, page?: number }} opts
     */
    async fetchNotifications({ unreadOnly = false, page = 1 } = {}) {
      this.loading = true
      try {
        const params = { page }
        if (unreadOnly) params.unread = 1
        const { data } = await api.get('/notifications', { params })
        this.items = data.data
        this.meta = data.meta
        this.unreadCount = data.meta?.unread_count ?? 0
      } catch (e) {
        this.error = e.response?.data?.message || 'Failed to load notifications'
      } finally {
        this.loading = false
      }
    },

    /** Mark a single notification as read. */
    async markRead(notificationId) {
      try {
        await api.post(`/notifications/${notificationId}/read`)
        const item = this.items.find((n) => n.id === notificationId)
        if (item && !item.read_at) {
          item.read_at = new Date().toISOString()
          if (this.unreadCount > 0) this.unreadCount--
        }
      } catch {
        // non-fatal — optimistic update already applied
      }
    },

    /** Mark all notifications as read. */
    async markAllRead() {
      try {
        await api.post('/notifications/read-all')
        for (const item of this.items) {
          if (!item.read_at) item.read_at = new Date().toISOString()
        }
        this.unreadCount = 0
      } catch {
        // non-fatal
      }
    },

    /**
     * Execute a declarative action button click.
     *
     * POSTs to the server-side gateway which re-checks staleness and proxies
     * to the underlying endpoint. Never calls the underlying endpoint directly.
     *
     * @param {number} notificationId
     * @param {string} actionKey
     * @returns {Promise<object>} the gateway response body
     */
    async runAction(notificationId, actionKey) {
      this.actionLoading = notificationId
      try {
        const { data } = await api.post(
          `/notifications/${notificationId}/actions/${actionKey}`,
        )
        // Mark as read after a successful action
        await this.markRead(notificationId)
        return data
      } finally {
        this.actionLoading = null
      }
    },

    /**
     * Add a notification optimistically (used by useToast's persist:true path).
     * The object should match the server shape so the bell renders correctly
     * before the next fetchNotifications() round-trip.
     *
     * @param {object} notification - { id, type, payload, actions, read_at, created_at }
     */
    addOptimistic(notification) {
      // Prepend so newest is first
      this.items.unshift(notification)
      if (!notification.read_at) {
        this.unreadCount++
      }
    },

    /**
     * Remove an optimistic notification (e.g. on fetch-refresh reconcile).
     * @param {number|string} id
     */
    removeOptimistic(id) {
      const idx = this.items.findIndex((n) => n.id === id)
      if (idx !== -1) {
        const item = this.items[idx]
        this.items.splice(idx, 1)
        if (!item.read_at && this.unreadCount > 0) this.unreadCount--
      }
    },
  },
})
