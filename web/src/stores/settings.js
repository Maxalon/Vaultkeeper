import { defineStore } from 'pinia'

const STORAGE_KEY = 'vaultkeeper-settings'

const defaults = {
  density: 'default', // 'compact' | 'default' | 'cozy'
  hoverMode: 'expand', // 'expand' (current behaviour) | 'peek' (popover)
  displayMode: 'A', // 'A' typed-name strips | 'B' corner-badge strips

  // Location sidebar customization
  sidebarShowEdit: true,
  sidebarShowDelete: false,
  sidebarShowDrag: true,
  sidebarShowCountDrawer: true,
  sidebarShowCountBinder: true,
  sidebarShowCountDeck: true,
  sidebarShowFormatBadge: true,
  sidebarGroupCounter: 'cards', // 'cards' | 'locations' | 'off'
}

const SIDEBAR_BOOL_KEYS = [
  'sidebarShowEdit',
  'sidebarShowDelete',
  'sidebarShowDrag',
  'sidebarShowCountDrawer',
  'sidebarShowCountBinder',
  'sidebarShowCountDeck',
  'sidebarShowFormatBadge',
]

export const useSettingsStore = defineStore('settings', {
  state: () => ({ ...defaults }),

  actions: {
    hydrate() {
      try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
        for (const key of Object.keys(defaults)) {
          if (raw[key] !== undefined) this[key] = raw[key]
        }
      } catch {
        // Ignore — keep defaults if storage is corrupt.
      }
    },

    persist() {
      const payload = {}
      for (const key of Object.keys(defaults)) payload[key] = this[key]
      localStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
    },

    setDensity(value) {
      if (!['compact', 'default', 'cozy'].includes(value)) return
      this.density = value
      document.documentElement.setAttribute('data-density', value)
      this.persist()
    },

    setHoverMode(value) {
      if (!['expand', 'peek'].includes(value)) return
      this.hoverMode = value
      this.persist()
    },

    setDisplayMode(value) {
      if (!['A', 'B'].includes(value)) return
      this.displayMode = value
      this.persist()
    },

    setSidebarBool(key, value) {
      if (!SIDEBAR_BOOL_KEYS.includes(key)) return
      this[key] = !!value
      this.persist()
    },

    setSidebarGroupCounter(value) {
      if (!['cards', 'locations', 'off'].includes(value)) return
      this.sidebarGroupCounter = value
      this.persist()
    },
  },
})
