<script setup>
import { computed, ref } from 'vue'
import { useTabsStore } from '../../stores/tabs'
import { useDeckStore } from '../../stores/deck'
import { tabRegistry } from './tabRegistry'
import TabBar from './TabBar.vue'

const props = defineProps({
  node: { type: Object, required: true },
})

const tabs = useTabsStore()
const deck = useDeckStore()

const activeTab = computed(() => props.node.tabs[props.node.activeIndex] ?? null)

// When the tab tree's root IS this leaf (no splits yet), the TopbarTabBar
// renders the bar in the AppTopBar and this inline bar is skipped. Once
// the user splits, tabs.root.type flips to 'split' and every leaf renders
// its own inline bar automatically.
const isRootLeaf = computed(
  () => tabs.root?.type === 'leaf' && tabs.root.id === props.node.id,
)

const activeEntry = computed(() => {
  const t = activeTab.value
  if (!t) return null
  return tabRegistry[t.type] || null
})

// Drop-zone hover bucket: 'center'|'left'|'right'|'top'|'bottom'
const dragBucket = ref(null)

function bucketFromEvent(e) {
  const rect = e.currentTarget.getBoundingClientRect()
  const x = (e.clientX - rect.left) / rect.width
  const y = (e.clientY - rect.top)  / rect.height
  const EDGE = 0.18
  if (x < EDGE)     return 'left'
  if (x > 1 - EDGE) return 'right'
  if (y < EDGE)     return 'top'
  if (y > 1 - EDGE) return 'bottom'
  return 'center'
}

function onDragOver(e) {
  const types = e.dataTransfer?.types
  if (!types || !types.includes('application/vk-tab')) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragBucket.value = bucketFromEvent(e)
}

function onDragLeave() {
  dragBucket.value = null
}

function onDrop(e) {
  const raw = e.dataTransfer?.getData('application/vk-tab')
  dragBucket.value = null
  if (!raw) return
  let payload
  try { payload = JSON.parse(raw) } catch { return }
  const bucket = bucketFromEvent(e)
  if (bucket === 'center') {
    tabs.moveTab(payload.tabId, props.node.id, -1)
    return
  }
  // Split and land tab in the new leaf.
  const tabInfo = findTabLocally(payload)
  const dir = (bucket === 'left' || bucket === 'right') ? 'horizontal' : 'vertical'
  const placement = (bucket === 'left' || bucket === 'top') ? 'before' : 'after'
  // Preserve source tab shape — strip it from source, then split.
  const srcPanel = findTabsOwner(payload.sourcePanelId)
  if (!srcPanel) return
  const srcIdx = srcPanel.tabs.findIndex((t) => t.id === payload.tabId)
  if (srcIdx === -1) return
  const draggedTab = srcPanel.tabs[srcIdx]
  // Remove from source first
  srcPanel.tabs.splice(srcIdx, 1)
  if (!srcPanel.tabs.length && srcPanel.id !== props.node.id) {
    tabs.collapsePanel(srcPanel.id)
  } else if (srcPanel.activeIndex >= srcPanel.tabs.length) {
    srcPanel.activeIndex = Math.max(0, srcPanel.tabs.length - 1)
  }
  tabs.splitPanel(props.node.id, dir, draggedTab, placement)
}

function findTabLocally(payload) {
  const panel = findTabsOwner(payload.sourcePanelId)
  if (!panel) return null
  return panel.tabs.find((t) => t.id === payload.tabId)
}

function findTabsOwner(id) {
  const stack = [tabs.root]
  while (stack.length) {
    const n = stack.pop()
    if (!n) continue
    if (n.id === id && n.type === 'leaf') return n
    if (n.type === 'split') {
      stack.push(n.left, n.right)
    }
  }
  return null
}

function onOpenCatalog(panelId) {
  tabs.openTab('catalog', {}, panelId || props.node.id)
}

function onAddToDeck(payload) {
  if (!deck.deck || !payload?.scryfall_id) return
  deck.addEntry(deck.deck.id, {
    scryfall_id: payload.scryfall_id,
    zone: payload.zone || 'main',
  })
}

// Mark undock state so DeckTabContent hides its side/maybe sections.
// (Also visible as tab presence — this is a small UX hint.)
function syncUndocked() {
  const hasSide  = tabs.leaves.some((l) => l.tabs.some((t) => t.type === 'side'))
  const hasMaybe = tabs.leaves.some((l) => l.tabs.some((t) => t.type === 'maybe'))
  deck.setUndocked('side',  hasSide)
  deck.setUndocked('maybe', hasMaybe)
}

// Re-check on every render (cheap).
syncUndocked()
</script>

<template>
  <div
    class="leaf-node"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop"
  >
    <TabBar v-if="!isRootLeaf" :node="node" @open-catalog="onOpenCatalog" />

    <div class="leaf-body">
      <component
        v-if="activeTab && activeEntry"
        :is="activeEntry.component"
        v-bind="activeEntry.props(activeTab, { deckId: deck.deck?.id ?? null })"
        @add-to-deck="onAddToDeck"
      />
      <div v-else class="leaf-empty">
        No active tab
      </div>

      <div
        v-if="dragBucket"
        class="drop-indicator"
        :class="`drop-${dragBucket}`"
      />
    </div>
  </div>
</template>

<style>
.leaf-node {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  position: relative;
}
.leaf-body {
  flex: 1 1 auto;
  position: relative;
  overflow: auto;
  min-height: 0;
}
.leaf-empty {
  display: grid;
  place-items: center;
  height: 100%;
  color: var(--vk-fg-dim, #a8a396);
  font-size: 0.9rem;
}
.drop-indicator {
  position: absolute;
  background: rgba(201, 157, 61, 0.25);
  border: 1px dashed var(--vk-accent, #c99d3d);
  pointer-events: none;
}
.drop-center { inset: 8px; }
.drop-left   { top: 0; bottom: 0; left: 0;  width: 40%; }
.drop-right  { top: 0; bottom: 0; right: 0; width: 40%; }
.drop-top    { left: 0; right: 0; top: 0;    height: 40%; }
.drop-bottom { left: 0; right: 0; bottom: 0; height: 40%; }
</style>
