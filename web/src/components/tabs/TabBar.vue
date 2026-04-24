<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTabsStore } from '../../stores/tabs'
import { useDeckStore } from '../../stores/deck'

const props = defineProps({
  node: { type: Object, required: true },
  variant: { type: String, default: 'panel' }, // 'panel' | 'topbar'
})
const emit = defineEmits(['open-catalog'])

const tabs = useTabsStore()
const deck = useDeckStore()

const menuOpen = ref(false)
const addBtnRef = ref(null)
const barRef = ref(null)
const menuStyle = ref({ top: '0px', left: '0px' })

async function toggleMenu() {
  if (menuOpen.value) {
    menuOpen.value = false
    return
  }
  menuOpen.value = true
  await nextTick()
  if (addBtnRef.value) {
    const rect = addBtnRef.value.getBoundingClientRect()
    menuStyle.value = {
      top: `${rect.bottom}px`,
      left: `${rect.left}px`,
    }
  }
  document.addEventListener('mousedown', onDocMouseDown, true)
}

function onDocMouseDown(e) {
  if (!menuOpen.value) return
  const target = e.target
  if (addBtnRef.value && addBtnRef.value.contains(target)) return
  if (target.closest && target.closest('.tab-add-menu')) return
  menuOpen.value = false
  document.removeEventListener('mousedown', onDocMouseDown, true)
}

// Convert vertical wheel deltas into horizontal scroll when the bar is
// actually overflowing. Skips when the bar fits (so it doesn't eat page
// scroll) and when the user is already scrolling horizontally (trackpad).
function onWheel(e) {
  const el = barRef.value
  if (!el) return
  if (el.scrollWidth <= el.clientWidth) return
  if (e.deltaX !== 0) return
  if (e.deltaY === 0) return
  e.preventDefault()
  el.scrollLeft += e.deltaY
}

onMounted(() => {
  // Non-passive so preventDefault() can stop the page from scrolling.
  if (barRef.value) {
    barRef.value.addEventListener('wheel', onWheel, { passive: false })
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocMouseDown, true)
  if (barRef.value) {
    barRef.value.removeEventListener('wheel', onWheel, { passive: false })
  }
})

function onTabClick(i) {
  tabs.setActiveTab(props.node.id, i)
}

function onClose(i) {
  const tab = props.node.tabs[i]
  tabs.closeTab(props.node.id, i)
  if (tab && (tab.type === 'side' || tab.type === 'maybe')) {
    deck.setUndocked(tab.type, false)
  }
}

function onRedock(i) {
  const tab = props.node.tabs[i]
  if (!tab) return
  tabs.closeTab(props.node.id, i)
  deck.setUndocked(tab.type, false)
}

function onDragStart(e, tab) {
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData(
    'application/vk-tab',
    JSON.stringify({ tabId: tab.id, sourcePanelId: props.node.id }),
  )
}

function addTab(type) {
  menuOpen.value = false
  document.removeEventListener('mousedown', onDocMouseDown, true)
  if (type === 'catalog') emit('open-catalog', props.node.id)
  else tabs.openTab(type, {}, props.node.id)
}
</script>

<template>
  <div ref="barRef" class="tab-bar" :class="`variant-${variant}`">
    <div
      v-for="(tab, i) in node.tabs"
      :key="tab.id"
      class="tab-chip"
      :class="{ 'tab-active': i === node.activeIndex }"
      draggable="true"
      @click="onTabClick(i)"
      @dragstart="onDragStart($event, tab)"
    >
      <span class="tab-label">{{ tab.label }}</span>
      <button
        v-if="tab.type === 'side' || tab.type === 'maybe'"
        type="button"
        class="tab-redock"
        title="Redock"
        @click.stop="onRedock(i)"
      >↓</button>
      <button
        type="button"
        class="tab-close"
        title="Close tab"
        @click.stop="onClose(i)"
      >×</button>
    </div>

    <div class="tab-add">
      <button ref="addBtnRef" type="button" class="tab-add-btn" @click="toggleMenu">+</button>
    </div>
  </div>
  <Teleport to="body">
    <div v-if="menuOpen" class="tab-add-menu" :style="menuStyle">
      <button type="button" @click="addTab('catalog')">Catalog</button>
      <button type="button" @click="addTab('analysis')">Analysis</button>
      <button type="button" @click="addTab('illegalities')">Illegalities</button>
    </div>
  </Teleport>
</template>

<style>
.tab-bar {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.3rem 0.4rem 0;
  background: var(--bg-2, #1d1c1a);
  border-bottom: 1px solid var(--hairline, #33312c);
  overflow-x: auto;
  overflow-y: hidden; /* pin the vertical axis so `overflow-x: auto` doesn't
                          silently promote `overflow-y: visible` to auto
                          (per css-overflow-3 §3) and render a phantom
                          vertical scrollbar. */
  flex: 0 0 auto;
}
.tab-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.7rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  color: var(--ink-70, #a8a396);
  cursor: pointer;
  font-size: 0.85rem;
  user-select: none;
  flex: 0 0 auto;
}
.tab-chip.tab-active {
  background: var(--bg-2, #1d1c1a);
  color: var(--ink-90, #e9e4d6);
  border-bottom: 1px solid var(--bg-2, #1d1c1a);
  margin-bottom: -1px;
}
.tab-close, .tab-redock, .tab-add-btn {
  background: transparent;
  border: none;
  color: inherit;
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0 0.2rem;
  line-height: 1;
}
.tab-close:hover, .tab-redock:hover { color: var(--amber, #c99d3d); }
.tab-add {
  position: relative;
  flex: 0 0 auto;
}
.tab-add-btn {
  padding: 0.3rem 0.5rem;
  border-radius: 4px;
  color: var(--ink-70, #a8a396);
}
.tab-add-btn:hover { background: var(--bg-2, #26241f); }
.tab-add-menu {
  position: fixed;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 4px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  min-width: 140px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}
.tab-add-menu button {
  background: transparent;
  border: none;
  color: inherit;
  padding: 0.5rem 0.8rem;
  text-align: left;
  cursor: pointer;
  font-size: 0.85rem;
}
.tab-add-menu button:hover { background: var(--bg-2, #1d1c1a); }
</style>
