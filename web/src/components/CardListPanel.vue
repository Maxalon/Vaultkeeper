<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useCollectionStore } from '../stores/collection'
import { useSettingsStore } from '../stores/settings'
import CardStrip from './CardStrip.vue'
import CardPeek from './CardPeek.vue'

const collection = useCollectionStore()
const settings = useSettingsStore()

const moveTarget = ref('')
const realLocations = computed(() => collection.locations)

const CARD_GAP = 16

const stripStack = ref(null)
const columnCount = ref(1)
let resizeObserver = null

function readCardWidth() {
  const v = getComputedStyle(document.documentElement).getPropertyValue('--card-width').trim()
  const n = parseFloat(v)
  return Number.isFinite(n) && n > 0 ? n : 146
}

function recomputeColumns() {
  if (!stripStack.value) return
  const w = stripStack.value.clientWidth
  const cardW = readCardWidth()
  const n = Math.max(1, Math.floor((w + CARD_GAP) / (cardW + CARD_GAP)))
  columnCount.value = n
}

const columns = computed(() => {
  const n = columnCount.value
  const entries = collection.filteredEntries
  if (entries.length === 0) return []
  const perColumn = Math.ceil(entries.length / n)
  const cols = Array.from({ length: n }, () => [])
  entries.forEach((entry, idx) => {
    cols[Math.floor(idx / perColumn)].push(entry)
  })
  return cols.filter((c) => c.length > 0)
})

onMounted(() => {
  if (stripStack.value) {
    recomputeColumns()
    resizeObserver = new ResizeObserver(() => recomputeColumns())
    resizeObserver.observe(stripStack.value)
  }
})
onBeforeUnmount(() => {
  if (resizeObserver) resizeObserver.disconnect()
})

// Density changes update --card-width on <html>; recompute columns when
// the user toggles density in settings.
watch(() => settings.density, () => {
  requestAnimationFrame(recomputeColumns)
})

async function batchMove() {
  if (!moveTarget.value) return
  await collection.batchMove(Number(moveTarget.value))
  moveTarget.value = ''
}

watch(() => collection.entries.length, () => {
  requestAnimationFrame(recomputeColumns)
})

function onSelect(id) {
  if (collection.selecting) collection.toggleSelect(id)
  else collection.setActiveEntry(id)
}

// ── Peek (popover hover) state ──────────────────────────────────────────
const peek = ref({ entry: null, x: 0, y: 0, visible: false })

function onPeekShow({ entry, rect }) {
  if (!stripStack.value) return

  // Peek width tracks --card-width so the popover always matches the
  // strip size the user picked via Density. Height derives from the
  // Scryfall card aspect ratio (63×88) — same ratio CardPeek.vue uses.
  // DFCs render both faces side-by-side so the popover is twice as wide
  // plus an 8px gap (matches CardPeek.vue's .is-dfc layout).
  const isDfc = !!(entry?.card?.is_dfc && entry?.card?.image_normal_back)
  const cardW = readCardWidth()
  const peekW = isDfc ? cardW * 2 + 8 : cardW
  const peekH = Math.round(cardW * 88 / 63)
  const gap = 10

  // Position peek to the right of the hovered strip, flip to left if it
  // would overflow the main area on the right.
  let x = rect.right + gap
  if (x + peekW > window.innerWidth - 12) {
    x = rect.left - peekW - gap
  }
  // Vertically centre the peek on the strip's middle, clamped to viewport.
  let y = rect.top + rect.height / 2 - peekH / 2
  y = Math.max(12, Math.min(y, window.innerHeight - peekH - 12))

  peek.value = { entry, x, y, visible: true }
}

function onPeekHide() {
  peek.value = { ...peek.value, visible: false }
}
</script>

<template>
  <main class="card-list-panel">
    <div v-if="collection.selecting" class="select-bar">
      <span class="sel-count">{{ collection.selectedIds.length }} selected</span>
      <button type="button" class="sel-btn" @click="collection.selectAll()">All</button>
      <button type="button" class="sel-btn" @click="collection.clearSelection()">None</button>
      <div class="sel-move">
        <select v-model="moveTarget">
          <option value="" disabled selected>Move to…</option>
          <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
        </select>
        <button
          type="button"
          class="sel-btn primary"
          :disabled="collection.selectedIds.length === 0 || !moveTarget"
          @click="batchMove"
        >Move</button>
      </div>
    </div>

    <div class="list-area">
      <div ref="stripStack" class="strip-stack">
        <template v-if="collection.loading && !collection.entries.length">
          <div class="empty">Loading…</div>
        </template>
        <template v-else-if="!columns.length">
          <div class="empty">No cards match the current filters</div>
        </template>
        <template v-else>
          <div v-for="(col, ci) in columns" :key="ci" class="column">
            <CardStrip
              v-for="(entry, ei) in col"
              :key="entry.id"
              :entry="entry"
              :active="entry.id === collection.activeEntryId"
              :selected="collection.selectedIds.includes(entry.id)"
              :last="ei === col.length - 1"
              :hover-mode="settings.hoverMode"
              @select="onSelect"
              @peek-show="onPeekShow"
              @peek-hide="onPeekHide"
            />
          </div>
        </template>
      </div>
    </div>

    <CardPeek
      :entry="peek.entry"
      :x="peek.x"
      :y="peek.y"
      :visible="peek.visible"
    />
  </main>
</template>

<style scoped>
.card-list-panel {
  display: flex;
  flex-direction: column;
  background: var(--vk-bg-0);
  overflow: hidden;
  min-height: 0;
  flex: 1;
}
.select-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: var(--vk-bg-2);
  border-bottom: 1px solid var(--vk-line);
  flex-shrink: 0;
}
.sel-count {
  font-size: 12px;
  color: var(--vk-gold);
  font-weight: 600;
  margin-right: 4px;
}
.sel-btn {
  padding: 5px 10px;
  font-size: 11px;
  background: transparent;
  border: 1px solid var(--vk-line);
  color: var(--vk-ink-2);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.sel-btn:hover {
  border-color: var(--vk-ink-4);
  color: var(--vk-ink-1);
  background: var(--vk-bg-1);
}
.sel-btn.primary {
  background: var(--vk-gold);
  border-color: var(--vk-gold);
  color: #1a1408;
  font-weight: 600;
}
.sel-btn.primary:disabled { opacity: 0.4; cursor: not-allowed; }
.sel-move {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
}
.sel-move select {
  font-size: 12px;
  padding: 5px 8px;
  width: auto;
  min-width: 140px;
  background: var(--vk-bg-0);
  border: 1px solid var(--vk-line);
  color: var(--vk-ink-1);
  border-radius: var(--radius-sm);
}

.list-area {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-gutter: stable;
  padding: 14px 20px var(--strip-expanded);
}
.strip-stack {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  gap: 16px;
  width: 100%;
}
.column {
  display: flex;
  flex-direction: column;
  width: var(--card-width);
  flex-shrink: 0;
  contain: layout style;
}
.empty {
  flex: 1;
  color: var(--vk-ink-3);
  text-align: center;
  padding: 60px 20px;
  font-style: italic;
}
</style>
