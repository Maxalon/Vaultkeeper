import { defineStore } from 'pinia'
import api from '../lib/api'

/**
 * Pinia store for the friend graph.
 *
 * Handles accepted friends, incoming/outgoing requests, and the username
 * search surface used by AddFriendModal. All network calls go through the
 * shared api.js axios instance (JWT-authed, 401-redirect wired).
 */
export const useFriendsStore = defineStore('friends', {
  state: () => ({
    /** Array of accepted friend objects: { id, username, friends_since } */
    friends: [],
    /** Incoming pending requests: { id, status, direction: 'incoming', user: { id, username }, created_at } */
    incomingRequests: [],
    /** Outgoing pending requests: { id, status, direction: 'outgoing', user: { id, username }, created_at } */
    outgoingRequests: [],
    /** Results from the last /users/search call */
    searchResults: [],
    loading: false,
    searchLoading: false,
    error: null,
  }),

  getters: {
    /** Total pending incoming request count — used for badge display */
    pendingIncomingCount: (state) => state.incomingRequests.length,

    friendById: (state) => (userId) =>
      state.friends.find((f) => f.id === userId) ?? null,

    isFriend: (state) => (userId) =>
      state.friends.some((f) => f.id === userId),
  },

  actions: {
    async fetchFriends() {
      this.loading = true
      try {
        const { data } = await api.get('/friends')
        this.friends = data.data
      } catch (e) {
        this.error = e.response?.data?.message || 'Failed to load friends'
      } finally {
        this.loading = false
      }
    },

    async fetchRequests() {
      this.loading = true
      try {
        const [incoming, outgoing] = await Promise.all([
          api.get('/friends/requests', { params: { direction: 'incoming' } }),
          api.get('/friends/requests', { params: { direction: 'outgoing' } }),
        ])
        this.incomingRequests = incoming.data.data
        this.outgoingRequests = outgoing.data.data
      } catch (e) {
        this.error = e.response?.data?.message || 'Failed to load requests'
      } finally {
        this.loading = false
      }
    },

    /**
     * Debounced username search. The component is responsible for debouncing
     * calls to this action; we just fire the request.
     *
     * @param {string} q - username prefix query
     */
    async searchUsers(q) {
      if (!q || q.trim().length < 1) {
        this.searchResults = []
        return
      }
      this.searchLoading = true
      try {
        const { data } = await api.get('/users/search', { params: { q: q.trim() } })
        this.searchResults = data.data
      } catch (e) {
        this.searchResults = []
      } finally {
        this.searchLoading = false
      }
    },

    clearSearch() {
      this.searchResults = []
    },

    /**
     * Send a friend request by username.
     * Returns the created request object on success.
     * Throws on 409 (duplicate request) or other errors so the caller can toast.
     */
    async sendRequest(username) {
      const { data } = await api.post('/friends/requests', { username })
      await this.fetchRequests()
      return data
    },

    /**
     * Accept or decline an incoming request.
     * @param {number} requestId
     * @param {'accept'|'decline'} action
     */
    async respondToRequest(requestId, action) {
      await api.patch(`/friends/requests/${requestId}`, { action })
      await Promise.all([this.fetchFriends(), this.fetchRequests()])
    },

    /**
     * Cancel an outgoing request.
     * @param {number} requestId
     */
    async cancelRequest(requestId) {
      await api.delete(`/friends/requests/${requestId}`)
      await this.fetchRequests()
    },

    /**
     * Unfriend a user. Tears down the friendship symmetrically server-side.
     * @param {number} userId
     */
    async unfriend(userId) {
      await api.delete(`/friends/${userId}`)
      this.friends = this.friends.filter((f) => f.id !== userId)
    },
  },
})
