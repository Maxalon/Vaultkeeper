import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useTabsStore } from '../tabs.js'

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
})

describe('tabs store', () => {
  it('initialises with a single deck tab', () => {
    const tabs = useTabsStore()
    expect(tabs.root.type).toBe('leaf')
    expect(tabs.root.tabs).toHaveLength(1)
    expect(tabs.root.tabs[0].type).toBe('deck')
  })

  it('openTab reuses single-instance types', () => {
    const tabs = useTabsStore()
    const firstId = tabs.root.tabs[0].id
    const reopened = tabs.openTab('deck')
    expect(reopened).toBe(firstId)
  })

  it('openTab creates new multi-instance types', () => {
    const tabs = useTabsStore()
    const a = tabs.openTab('catalog')
    const b = tabs.openTab('catalog')
    expect(a).not.toBe(b)
    expect(tabs.root.tabs).toHaveLength(3)
  })

  it('splitPanel produces a split tree', () => {
    const tabs = useTabsStore()
    const newLeaf = tabs.splitPanel(
      tabs.root.id,
      'horizontal',
      { id: 'x', type: 'analysis', label: 'Analysis' },
      'after',
    )
    expect(tabs.root.type).toBe('split')
    expect(tabs.root.direction).toBe('horizontal')
    expect(tabs.root.right.id).toBe(newLeaf)
  })

  it('closeTab on single-tab leaf collapses sibling', () => {
    const tabs = useTabsStore()
    tabs.splitPanel(
      tabs.root.id,
      'horizontal',
      { id: 'x', type: 'analysis', label: 'Analysis' },
      'after',
    )
    const rightLeaf = tabs.root.right
    tabs.closeTab(rightLeaf.id, 0)
    expect(tabs.root.type).toBe('leaf')
    expect(tabs.root.tabs[0].type).toBe('deck')
  })

  it('undockSection / redockSection round-trip', () => {
    const tabs = useTabsStore()
    tabs.undockSection('side')
    expect(tabs.root.type).toBe('split')
    const hadSide = tabs.leaves.some((l) => l.tabs.some((t) => t.type === 'side'))
    expect(hadSide).toBe(true)

    tabs.redockSection('side')
    expect(tabs.root.type).toBe('leaf')
  })

  it('persists layout to localStorage', () => {
    const tabs = useTabsStore()
    tabs.openTab('catalog')
    const raw = localStorage.getItem('vaultkeeper_tab_layout')
    expect(raw).toBeTruthy()
    const parsed = JSON.parse(raw)
    expect(parsed.tabs.some((t) => t.type === 'catalog')).toBe(true)
  })

  it('hydration falls back to default on corrupt storage', () => {
    localStorage.setItem('vaultkeeper_tab_layout', 'not json')
    setActivePinia(createPinia())
    const tabs = useTabsStore()
    expect(tabs.root.type).toBe('leaf')
    expect(tabs.root.tabs[0].type).toBe('deck')
  })
})
