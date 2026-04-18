import { defineStore } from 'pinia'
import api from '../lib/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') || null,
    user: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
  },

  actions: {
    async login(credentials) {
      const { data } = await api.post('/auth/login', credentials)
      this.token = data.access_token
      this.user = data.user
      localStorage.setItem('token', data.access_token)
      return data
    },

    async register(payload) {
      const { data } = await api.post('/auth/register', payload)
      this.token = data.access_token
      this.user = data.user
      localStorage.setItem('token', data.access_token)
      return data
    },

    async fetchMe() {
      const { data } = await api.get('/auth/me')
      this.user = data
      return data
    },

    async logout() {
      try {
        await api.post('/auth/logout')
      } catch (e) {
        // ignore — clear local state regardless
      }
      this.token = null
      this.user = null
      localStorage.removeItem('token')
    },
  },
})
