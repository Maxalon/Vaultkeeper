<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useCollectionStore } from '../stores/collection'
import CardStrip from './CardStrip.vue'

const collection = useCollectionStore()
const moveTarget = ref('')
const realLocations = computed(() => collection.locations)

const COLORS = ['W', 'U', 'B', 'R', 'G', 'C']
const RARITIES = ['common', 'uncommon', 'rare', 'mythic']
const TYPES = ['Creature', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Planeswalker', 'Land']
const SORTS = [
  { value: 'name', label: 'Name' },
  { value: 'color', label: 'Color' },
  { value: 'set_code', label: 'Set' },
  { value: 'rarity', label: 'Rarity' },
  { value: 'collector_number', label: 'Number' },
  { value: 'condition', label: 'Condition' },
]

// Card column geometry. CARD_WIDTH is read from the --card-width CSS
// variable on document root (set in main.js based on devicePixelRatio).
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
  // (n * card) + ((n - 1) * gap) ≤ w  →  n ≤ (w + gap) / (card + gap)
  const n = Math.max(1, Math.floor((w + CARD_GAP) / (cardW + CARD_GAP)))
  columnCount.value = n
}

// Group entries into one array per column. Filling top-to-bottom in column 1
// then column 2, etc., gives the user the same visual order as a single
// column when there's only one column — important when sort=name.
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

// Append a Scryfall syntax token to the search field. The store now parses
// these client-side via filteredEntries — fetchEntries strips them out and
// only sends the bare-name remainder to the backend.
function appendToken(token) {
  const trimmed = collection.filters.search.trim()
  collection.filters.search = trimmed ? `${trimmed} ${token}` : token
  // Token-only changes don't need a refetch (filteredEntries reacts), but
  // we still kick off a debounced refetch in case there's also a name part.
  scheduleFetch()
}

let debounceTimer = null
function scheduleFetch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => collection.fetchEntries(), 250)
}

function toggleOrder() {
  collection.filters.order = collection.filters.order === 'asc' ? 'desc' : 'asc'
  collection.fetchEntries()
}

function onSelect(id) {
  if (collection.selecting) {
    collection.toggleSelect(id)
  } else {
    collection.setActiveEntry(id)
  }
}

async function batchMove() {
  if (!moveTarget.value) return
  await collection.batchMove(Number(moveTarget.value))
  moveTarget.value = ''
}

watch(() => collection.filters.sort, () => collection.fetchEntries())
// When the location changes, the column count may need to recompute on the
// next tick (the strip-stack height can shift the scrollbar's presence).
watch(() => collection.entries.length, () => {
  // Vue may have already laid out — recompute on next frame to be safe.
  requestAnimationFrame(recomputeColumns)
})
</script>

<template>
  <main class="card-list-panel">
    <div class="filter-bar">
      <div class="dropdowns">
        <select id="filter-color" @change="appendToken('c:' + $event.target.value); $event.target.value = ''">
          <option value="" disabled selected>Color</option>
          <option v-for="c in COLORS" :key="c" :value="c.toLowerCase()">{{ c }}</option>
        </select>
        <select id="filter-type" @change="appendToken('t:' + $event.target.value.toLowerCase()); $event.target.value = ''">
          <option value="" disabled selected>Type</option>
          <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
        </select>
        <select id="filter-rarity" @change="appendToken('r:' + $event.target.value); $event.target.value = ''">
          <option value="" disabled selected>Rarity</option>
          <option v-for="r in RARITIES" :key="r" :value="r">{{ r }}</option>
        </select>
        <input
          class="set-input"
          type="text"
          maxlength="6"
          placeholder="Set"
          @keyup.enter="appendToken('s:' + $event.target.value); $event.target.value = ''"
        />
      </div>

      <div class="search-area">
        <input
          v-model="collection.filters.search"
          type="text"
          placeholder="Search by name…"
          @input="scheduleFetch"
        />
      </div>

      <div class="sort">
        <select v-model="collection.filters.sort">
          <option v-for="s in SORTS" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button type="button" class="order-btn" @click="toggleOrder" :title="collection.filters.order">
          {{ collection.filters.order === 'asc' ? '↑' : '↓' }}
        </button>
      </div>

      <button
        type="button"
        class="select-toggle"
        :class="{ active: collection.selecting }"
        @click="collection.toggleSelecting()"
        title="Multi-select"
      >Select</button>
    </div>

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
          <!-- One DOM container per column. CardStrip's hover-driven flex
               reflow stays scoped to each column. -->
          <div v-for="(col, ci) in columns" :key="ci" class="column">
            <CardStrip
              v-for="(entry, ei) in col"
              :key="entry.id"
              :entry="entry"
              :active="entry.id === collection.activeEntryId"
              :selected="collection.selectedIds.includes(entry.id)"
              :last="ei === col.length - 1"
              @select="onSelect"
            />
          </div>
        </template>
      </div>
    </div>
  </main>
</template>

<style scoped>
.card-list-panel {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: var(--bg-0);
  overflow: hidden;
  min-height: 0;
}
.filter-bar {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  background: var(--bg-1);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
/* Shared sizing for every control in the filter bar. */
.filter-bar select,
.filter-bar input,
.filter-bar button {
  height: 30px;
  font-size: 12px;
  padding: 0 10px;
  box-sizing: border-box;
}
.filter-bar select {
  appearance: none;
  padding-right: 26px;
  background-image: url('../assets/chevron-down.svg');
  background-repeat: no-repeat;
  background-position: right 8px center;
}
.dropdowns {
  display: flex;
  gap: 6px;
}
.dropdowns select, .dropdowns .set-input {
  width: auto;
  min-width: 80px;
}
.set-input {
  width: 70px !important;
}
.search-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 200px;
}
.hint {
  font-size: 10px;
  color: var(--text-faint);
  font-style: italic;
}
.sort {
  display: flex;
  align-items: center;
  gap: 4px;
}
.sort select {
  width: auto;
}
.select-toggle {
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text-dim);
  flex-shrink: 0;
}
.select-toggle:hover {
  border-color: var(--gold-dim);
  color: var(--text);
}
.select-toggle.active {
  background: var(--gold);
  border-color: var(--gold);
  color: var(--bg-0);
}
.select-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: var(--bg-2);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.sel-count {
  font-size: 12px;
  color: var(--gold);
  font-weight: 600;
  margin-right: 4px;
}
.sel-btn {
  padding: 5px 10px;
  font-size: 11px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text-dim);
}
.sel-btn:hover {
  border-color: var(--gold-dim);
  color: var(--text);
}
.sel-btn.primary {
  background: var(--gold);
  border-color: var(--gold);
  color: var(--bg-0);
  font-weight: 600;
}
.sel-btn.primary:disabled {
  opacity: 0.4;
}
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
}

/* The critical scroll fix: flex children need min-height: 0 to shrink
   below their content size, otherwise overflow-y: auto never triggers.
   `scrollbar-gutter: stable` reserves the scrollbar's width permanently
   so clientWidth doesn't jump when the scrollbar appears/disappears —
   without this, hovering a card grew the strip, triggered the scrollbar,
   shrank the inner width, dropped a column, slid the hovered card out
   from under the cursor, hid the scrollbar, and looped forever. */
.list-area {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-gutter: stable;
  /* Bottom padding must be at least --strip-expanded so the last card
     in a column can expand on hover without growing the scrollable area
     (which would cause a scroll-bounce loop). */
  padding: 24px 28px var(--strip-expanded);
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
  color: var(--text-faint);
  text-align: center;
  padding: 60px 20px;
  font-style: italic;
}
</style>
