import { defineStore } from 'pinia'

const STORAGE_KEY = 'vaultkeeper-settings'

// Sidebar width clamp. Below 200 the location names + footer buttons get
// cramped; above 360 we just steal space from the card list. 240 is the
// historical default that lined up with the old fixed topbar brand column.
const SIDEBAR_MIN = 200
const SIDEBAR_MAX = 360

const defaults = {
  density: 'default', // 'compact' | 'default' | 'cozy'
  hoverMode: 'expand', // 'expand' (current behaviour) | 'peek' (popover)
  displayMode: 'A', // 'A' typed-name strips | 'B' corner-badge strips
  sidebarWidth: 240,
  sidebarCollapsed: false,
}

function clampWidth(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return defaults.sidebarWidth
  return Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, Math.round(n)))
}

export const useSettingsStore = defineStore('settings', {
  state: () => ({ ...defaults }),

  getters: {
    sidebarMin: () => SIDEBAR_MIN,
    sidebarMax: () => SIDEBAR_MAX,
  },

  actions: {
    hydrate() {
      try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
        this.density = raw.density ?? defaults.density
        this.hoverMode = raw.hoverMode ?? defaults.hoverMode
        this.displayMode = raw.displayMode ?? defaults.displayMode
        this.sidebarWidth = clampWidth(raw.sidebarWidth ?? defaults.sidebarWidth)
        this.sidebarCollapsed = !!(raw.sidebarCollapsed ?? defaults.sidebarCollapsed)
      } catch {
        // Ignore — keep defaults if storage is corrupt.
      }
    },

    persist() {
      const payload = {
        density: this.density,
        hoverMode: this.hoverMode,
        displayMode: this.displayMode,
        sidebarWidth: this.sidebarWidth,
        sidebarCollapsed: this.sidebarCollapsed,
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

    /**
     * Update sidebar width. Caller is responsible for invoking this on a
     * throttled cadence during a drag — every pointermove would persist on
     * each frame otherwise. We persist only on commit (see persistSidebar).
     */
    setSidebarWidth(value) {
      this.sidebarWidth = clampWidth(value)
      // Keep the CSS variable in lockstep so the grid template reflects
      // the new width within the same frame as the pointer event.
      if (typeof document !== 'undefined') {
        document.documentElement.style.setProperty('--sidebar-width', `${this.sidebarWidth}px`)
      }
    },

    persistSidebar() {
      this.persist()
    },

    toggleSidebarCollapsed() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      this.persist()
    },
  },
})
