import { defineStore } from 'pinia'
import { nanoid } from 'nanoid'

/**
 * Terminal-style binary-tree tab layout. Panels are either:
 *   leaf:  { type: 'leaf',  id, tabs:[{id,type,label,options}], activeIndex }
 *   split: { type: 'split', id, direction: 'horizontal'|'vertical',
 *            splitAt, left, right }
 *
 * Persisted globally in localStorage under STORAGE_KEY; hydrated on app mount.
 * Group-collapse state is separate (per-deck, handled by DeckGrid).
 */

const STORAGE_KEY = 'vaultkeeper_tab_layout'

/** Tab types that are single-instance across the layout (shared state). */
const SINGLE_INSTANCE = new Set(['deck', 'side', 'maybe'])

function defaultLayout() {
  return {
    type: 'leaf',
    id: nanoid(8),
    tabs: [{ id: nanoid(8), type: 'deck', label: 'Deck' }],
    activeIndex: 0,
  }
}

function loadLayout() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return defaultLayout()
    const parsed = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object' || !parsed.type) {
      return defaultLayout()
    }
    return parsed
  } catch {
    return defaultLayout()
  }
}

function persist(root) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(root))
  } catch {
    // Ignore quota errors; layout lives in memory until next browser session.
  }
}

function findNode(root, id, parent = null) {
  if (!root) return null
  if (root.id === id) return { node: root, parent }
  if (root.type === 'split') {
    return (
      findNode(root.left, id, root) ||
      findNode(root.right, id, root) ||
      null
    )
  }
  return null
}

function findTabGlobally(root, tabId) {
  if (!root) return null
  if (root.type === 'leaf') {
    const idx = root.tabs.findIndex((t) => t.id === tabId)
    if (idx !== -1) return { panel: root, index: idx, tab: root.tabs[idx] }
    return null
  }
  return (
    findTabGlobally(root.left, tabId) ||
    findTabGlobally(root.right, tabId)
  )
}

function findFirstTabByType(root, type) {
  if (!root) return null
  if (root.type === 'leaf') {
    for (let i = 0; i < root.tabs.length; i++) {
      if (root.tabs[i].type === type) {
        return { panel: root, index: i, tab: root.tabs[i] }
      }
    }
    return null
  }
  return (
    findFirstTabByType(root.left, type) ||
    findFirstTabByType(root.right, type)
  )
}

function allLeaves(root, acc = []) {
  if (!root) return acc
  if (root.type === 'leaf') {
    acc.push(root)
    return acc
  }
  allLeaves(root.left, acc)
  allLeaves(root.right, acc)
  return acc
}

export const useTabsStore = defineStore('tabs', {
  state: () => ({
    root: loadLayout(),
  }),

  getters: {
    /** Flat list of all leaf panels (for the redock UI). */
    leaves(state) {
      return allLeaves(state.root)
    },
  },

  actions: {
    persist() {
      persist(this.root)
    },

    resetLayout() {
      this.root = defaultLayout()
      this.persist()
    },

    /**
     * Open or focus a tab of the given type. Single-instance types reuse
     * an existing tab; multi-instance types always create a new one.
     */
    openTab(type, options = {}) {
      if (SINGLE_INSTANCE.has(type)) {
        const hit = findFirstTabByType(this.root, type)
        if (hit) {
          hit.panel.activeIndex = hit.index
          this.persist()
          return hit.tab.id
        }
      }
      // Append to the first leaf.
      const leaves = allLeaves(this.root)
      const target = leaves[0]
      const tab = {
        id: nanoid(8),
        type,
        label: options.label || labelFor(type),
        options: options,
      }
      target.tabs.push(tab)
      target.activeIndex = target.tabs.length - 1
      this.persist()
      return tab.id
    },

    closeTab(panelId, tabIndex) {
      const hit = findNode(this.root, panelId)
      if (!hit || hit.node.type !== 'leaf') return
      const panel = hit.node
      panel.tabs.splice(tabIndex, 1)
      if (!panel.tabs.length) {
        this.collapsePanel(panel.id)
      } else if (panel.activeIndex >= panel.tabs.length) {
        panel.activeIndex = panel.tabs.length - 1
      }
      this.persist()
    },

    /**
     * Promote the remaining sibling up to the parent's slot when a leaf
     * becomes empty. If the collapsed panel is the root, reset to default.
     */
    collapsePanel(panelId) {
      if (this.root.id === panelId) {
        this.root = defaultLayout()
        return
      }
      const parentInfo = this._findParent(this.root, panelId)
      if (!parentInfo) return
      const { parent, side } = parentInfo
      const sibling = parent[side === 'left' ? 'right' : 'left']

      // Replace parent with sibling in grand-parent's slot (or at root).
      const grand = this._findParent(this.root, parent.id)
      if (!grand) {
        this.root = sibling
      } else {
        grand.parent[grand.side] = sibling
      }
    },

    _findParent(root, id, parent = null, side = null) {
      if (!root) return null
      if (root.id === id) {
        return parent ? { parent, side } : null
      }
      if (root.type !== 'split') return null
      return (
        this._findParent(root.left, id, root, 'left') ||
        this._findParent(root.right, id, root, 'right')
      )
    },

    /**
     * Replace `panelId` leaf with a split; `newTab` goes into the new half
     * (placement='before' = left/top, 'after' = right/bottom).
     */
    splitPanel(panelId, direction, newTab, placement = 'after') {
      const hit = findNode(this.root, panelId)
      if (!hit || hit.node.type !== 'leaf') return
      const existing = hit.node
      const fresh = {
        type: 'leaf',
        id: nanoid(8),
        tabs: [newTab],
        activeIndex: 0,
      }
      const split = {
        type: 'split',
        id: nanoid(8),
        direction,
        splitAt: 0.5,
        left:  placement === 'before' ? fresh : existing,
        right: placement === 'before' ? existing : fresh,
      }
      if (this.root.id === panelId) {
        this.root = split
      } else {
        const p = this._findParent(this.root, panelId)
        if (p) p.parent[p.side] = split
      }
      this.persist()
      return fresh.id
    },

    /** Move a tab between leaves (or within one). targetIndex=-1 appends. */
    moveTab(tabId, targetPanelId, targetIndex = -1) {
      const src = findTabGlobally(this.root, tabId)
      const dst = findNode(this.root, targetPanelId)
      if (!src || !dst || dst.node.type !== 'leaf') return
      const [tab] = src.panel.tabs.splice(src.index, 1)
      const target = dst.node
      if (targetIndex < 0 || targetIndex > target.tabs.length) {
        target.tabs.push(tab)
      } else {
        target.tabs.splice(targetIndex, 0, tab)
      }
      target.activeIndex = target.tabs.indexOf(tab)

      // Source panel left empty → collapse
      if (!src.panel.tabs.length && src.panel.id !== target.id) {
        this.collapsePanel(src.panel.id)
      } else if (src.panel.activeIndex >= src.panel.tabs.length) {
        src.panel.activeIndex = Math.max(0, src.panel.tabs.length - 1)
      }
      this.persist()
    },

    resizePanel(splitId, newSplitAt) {
      const hit = findNode(this.root, splitId)
      if (!hit || hit.node.type !== 'split') return
      hit.node.splitAt = Math.max(0.15, Math.min(0.85, newSplitAt))
      this.persist()
    },

    setActiveTab(panelId, index) {
      const hit = findNode(this.root, panelId)
      if (!hit || hit.node.type !== 'leaf') return
      hit.node.activeIndex = Math.max(
        0,
        Math.min(hit.node.tabs.length - 1, index),
      )
      this.persist()
    },

    /**
     * Split the Deck tab's leaf vertically (side-by-side) and open a new
     * {side|maybe} tab in the right half. Caller should set the matching
     * undock flag on the deck store afterwards.
     */
    undockSection(section) {
      const hit = findFirstTabByType(this.root, 'deck')
      if (!hit) return null
      const tab = {
        id: nanoid(8),
        type: section,
        label: section === 'side' ? 'Sideboard' : 'Maybeboard',
      }
      return this.splitPanel(hit.panel.id, 'horizontal', tab, 'after')
    },

    redockSection(section) {
      const hit = findFirstTabByType(this.root, section)
      if (!hit) return
      this.closeTab(hit.panel.id, hit.index)
    },
  },
})

function labelFor(type) {
  return (
    {
      deck: 'Deck',
      catalog: 'Catalog',
      analysis: 'Analysis',
      illegalities: 'Illegalities',
      side: 'Sideboard',
      maybe: 'Maybeboard',
    }[type] || type
  )
}
