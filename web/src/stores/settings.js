import { defineStore } from 'pinia'

const STORAGE_KEY = 'vaultkeeper-settings'

const defaults = {
  density: 'default', // 'compact' | 'default' | 'cozy'
  hoverMode: 'expand', // 'expand' (current behaviour) | 'peek' (popover)
  displayMode: 'A', // 'A' typed-name strips | 'B' corner-badge strips
}

export const useSettingsStore = defineStore('settings', {
  state: () => ({ ...defaults }),

  actions: {
    hydrate() {
      try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
        this.density = raw.density ?? defaults.density
        this.hoverMode = raw.hoverMode ?? defaults.hoverMode
        this.displayMode = raw.displayMode ?? defaults.displayMode
      } catch {
        // Ignore — keep defaults if storage is corrupt.
      }
    },

    persist() {
      const payload = {
        density: this.density,
        hoverMode: this.hoverMode,
        displayMode: this.displayMode,
      }
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
  },
})
