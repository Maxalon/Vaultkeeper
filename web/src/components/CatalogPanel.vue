<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import SyntaxSearch from './SyntaxSearch.vue'
import CardTile from './CardTile.vue'
import CatalogStrip from './CatalogStrip.vue'

/**
 * Self-contained catalog panel. No collection-store coupling. Consumes
 * the catalog Pinia store and emits `add-to-deck` intents upward (only
 * when `deckId` is bound — DB-3 wires the receiver).
 */
const props = defineProps({
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['add-to-deck'])

const catalog = useCatalogStore()
const scrollRef = ref(null)
const stripStack = ref(null)
let debounceTimer = null
let resizeObserver = null
const columnCount = ref(1)

const queryInput = ref(catalog.query)

// Strip mode layout — mirrors the collection view's column-packing: the
// scroll container's width / (card width + gap) determines how many
// columns fit, and entries are distributed top-to-bottom column-first
// so reading order flows down each column.
const STRIP_GAP = 16

function readCssPx(varName, fallback) {
  const v = getComputedStyle(document.documentElement).getPropertyValue(varName).trim()
  const n = parseFloat(v)
  return Number.isFinite(n) && n > 0 ? n : fallback
}

function recomputeColumns() {
  if (!stripStack.value) return
  const w = stripStack.value.clientWidth
  const cardW = readCssPx('--card-width', 146)
  const n = Math.max(1, Math.floor((w + STRIP_GAP) / (cardW + STRIP_GAP)))
  columnCount.value = n
}

const stripColumns = computed(() => {
  const n = columnCount.value
  const rows = catalog.results
  if (rows.length === 0 || n === 0) return []
  const perColumn = Math.ceil(rows.length / n)
  const cols = Array.from({ length: n }, () => [])
  rows.forEach((row, idx) => cols[Math.floor(idx / perColumn)].push(row))
  return cols.filter((c) => c.length > 0)
})

onMounted(() => {
  // Do NOT fire on mount with an empty query — the window-wrapped query is
  // a full-table scan of scryfall_cards (~113k rows) when there's no
  // WHERE filter, which saturates PHP-FPM workers. User has to type
  // something (or pick a chip) before we run a search.
  if (queryInput.value.trim() !== '') catalog.search(queryInput.value)

  if (stripStack.value) {
    recomputeColumns()
    resizeObserver = new ResizeObserver(() => recomputeColumns())
    resizeObserver.observe(stripStack.value)
  }
})

onBeforeUnmount(() => {
  if (resizeObserver) resizeObserver.disconnect()
})

// Whenever strip mode becomes active, results change, or the viewport
// resizes, recompute column count so the column pack stays tight.
watch([() => catalog.displayMode, () => catalog.results.length], () => {
  if (catalog.displayMode === 'strip') {
    // Wait a tick for the strip-stack element to exist (v-if toggle).
    requestAnimationFrame(recomputeColumns)
  }
})

// Debounced search on input. The store-side AbortController cancels any
// in-flight request when a new one starts, so mid-typing keystrokes don't
// queue up behind a slow earlier search and saturate the PHP-FPM pool.
// Longer debounce (400ms) also cuts the number of speculative fires.
// Single characters skip — "l" would match tens of thousands of cards,
// which is useless and slow; wait for a discriminating query.
watch(queryInput, (v) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  const trimmed = (v || '').trim()
  if (trimmed.length < 2) {
    // Clear old results so the UI state doesn't lie about what's shown.
    catalog.results = []
    catalog.total = 0
    catalog.warnings = []
    return
  }
  debounceTimer = setTimeout(() => {
    catalog.search(v)
  }, 400)
})

function onScroll() {
  const el = scrollRef.value
  if (!el) return
  const rem = el.scrollHeight - el.scrollTop - el.clientHeight
  if (rem < 400) catalog.loadMore()
}

const showWarnings = computed(() => catalog.warnings.length > 0 && !catalog.warningsDismissed)

const gridStyle = computed(() => {
  const min = catalog.cardSize === 'small' ? 140
    : catalog.cardSize === 'large' ? 280
    : 200
  return { '--card-min': `${min}px` }
})

function onAddToDeck(payload) {
  emit('add-to-deck', payload)
}
</script>

<template>
  <div class="catalog-panel">
    <header class="catalog-header">
      <div class="search-row">
        <SyntaxSearch v-model="queryInput" placeholder="Scryfall syntax: t:creature c:g cmc<=3…" />
        <div class="total" v-if="!catalog.loading">
          {{ catalog.total.toLocaleString() }} cards found
        </div>
        <div class="total loading" v-else>searching…</div>
      </div>

      <div class="chip-row">
        <button
          class="chip-btn"
          :class="{ active: catalog.ownedOnly }"
          type="button"
          @click="catalog.toggleOwnedOnly()"
        >{{ catalog.ownedOnly ? '★ Owned Only' : '☆ All Cards' }}</button>

        <div class="spacer" />

        <div class="mode-group">
          <button
            class="mode-btn"
            :class="{ active: catalog.displayMode === 'grid' }"
            type="button"
            :aria-pressed="catalog.displayMode === 'grid'"
            title="Grid view"
            @click="catalog.setDisplayMode('grid')"
          >▦ Grid</button>
          <button
            class="mode-btn"
            :class="{ active: catalog.displayMode === 'strip' }"
            type="button"
            :aria-pressed="catalog.displayMode === 'strip'"
            title="Strip view"
            @click="catalog.setDisplayMode('strip')"
          >☰ Strip</button>
        </div>

        <div v-if="catalog.displayMode === 'grid'" class="size-group">
          <button class="size-btn" :class="{ active: catalog.cardSize === 'small' }" @click="catalog.setCardSize('small')">S</button>
          <button class="size-btn" :class="{ active: catalog.cardSize === 'medium' }" @click="catalog.setCardSize('medium')">M</button>
          <button class="size-btn" :class="{ active: catalog.cardSize === 'large' }" @click="catalog.setCardSize('large')">L</button>
        </div>
      </div>

      <div v-if="deckId" class="deck-pills">
        <button
          v-if="catalog.deckFilters.format"
          class="pill"
          :class="{ on: catalog.deckFilters.formatActive }"
          @click="catalog.toggleDeckFilter('format')"
        >
          <span>{{ catalog.deckFilters.formatActive ? '✓' : '✕' }}</span>
          {{ catalog.deckFilters.format }}-legal
        </button>
        <button
          v-if="catalog.deckFilters.colorIdentity !== null"
          class="pill"
          :class="{ on: catalog.deckFilters.colorIdentityActive }"
          @click="catalog.toggleDeckFilter('identity')"
        >
          <span>{{ catalog.deckFilters.colorIdentityActive ? '✓' : '✕' }}</span>
          ⊆ {{ catalog.deckFilters.colorIdentity || 'colorless' }}
        </button>
      </div>

      <div v-if="showWarnings" class="warnings-banner">
        <span class="warn-icon">⚠</span>
        <span>Ignored: {{ catalog.warnings.join('; ') }}</span>
        <button class="dismiss" @click="catalog.dismissWarnings()">×</button>
      </div>
    </header>

    <div class="catalog-scroll" ref="scrollRef" @scroll.passive="onScroll">
      <div
        v-if="!catalog.loading && catalog.results.length === 0"
        class="empty"
      >
        <div v-if="(queryInput || '').trim().length < 2">
          Type at least 2 characters to search the catalog.
        </div>
        <div v-else>
          No cards match this search.
          <span v-if="showWarnings" class="empty-hint">
            (operators {{ catalog.warnings.join(', ') }} were ignored)
          </span>
        </div>
      </div>

      <div v-if="catalog.displayMode === 'grid'" class="grid" :style="gridStyle">
        <CardTile
          v-for="row in catalog.results"
          :key="row.oracle_id"
          :card="row"
          :size="catalog.cardSize"
          :deck-id="deckId"
          @add-to-deck="onAddToDeck"
        />
      </div>

      <div v-else ref="stripStack" class="strip-stack">
        <div v-for="(col, ci) in stripColumns" :key="ci" class="column">
          <CatalogStrip
            v-for="row in col"
            :key="row.oracle_id"
            :card="row"
            :deck-id="deckId"
            @add-to-deck="onAddToDeck"
          />
        </div>
      </div>

      <div v-if="catalog.loadingMore" class="load-more">Loading more…</div>
    </div>
  </div>
</template>

<style scoped>
.catalog-panel {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  min-height: 0;
  height: 100%;
  background: var(--vk-bg-0);
  overflow: hidden;
}

.catalog-header {
  flex-shrink: 0;
  border-bottom: 1px solid var(--vk-line);
  padding: 10px 16px 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.search-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.search-row :deep(.vk-syntax-search) { flex: 1; }
.total {
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  color: var(--vk-ink-3);
  white-space: nowrap;
}
.total.loading { color: var(--vk-gold-dim); }

.chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.chip-btn, .size-btn, .mode-btn {
  background: var(--vk-bg-1);
  border: 1px solid var(--vk-line);
  color: var(--vk-ink-2);
  font-family: inherit;
  font-size: 11px;
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: all 0.1s ease;
}
.chip-btn:hover { background: var(--vk-bg-2); color: var(--vk-ink-1); }
.chip-btn.active { background: var(--vk-gold-dim); color: #1a1408; border-color: var(--vk-gold); }
.chip-btn.token { font-family: var(--font-mono), monospace; font-size: 10px; }

.spacer { flex: 1; }

.mode-group, .size-group { display: flex; gap: 0; }
.size-btn, .mode-btn { padding: 4px 10px; border-radius: 0; }
.mode-btn:first-child, .size-btn:first-child { border-radius: var(--radius-sm) 0 0 var(--radius-sm); }
.mode-btn:last-child,  .size-btn:last-child  { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }
.mode-btn + .mode-btn, .size-btn + .size-btn { border-left: 0; }
.mode-btn:hover { background: var(--vk-bg-2); color: var(--vk-ink-1); }
.mode-btn.active, .size-btn.active { background: var(--vk-gold); color: #1a1408; border-color: var(--vk-gold); }

.mode-group + .size-group { margin-left: 8px; }

.deck-pills { display: flex; gap: 6px; }
.pill {
  background: var(--vk-bg-1);
  border: 1px solid var(--vk-line);
  color: var(--vk-ink-3);
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 12px;
  cursor: pointer;
  display: inline-flex;
  gap: 6px;
  align-items: center;
}
.pill.on { color: var(--vk-ink-1); border-color: var(--vk-gold-dim); }
.pill span { font-family: var(--font-mono), monospace; font-size: 10px; }

.warnings-banner {
  display: flex;
  gap: 10px;
  align-items: center;
  background: rgba(251, 146, 60, 0.08);
  border: 1px solid rgba(251, 146, 60, 0.3);
  border-radius: var(--radius-sm);
  padding: 6px 10px;
  font-size: 12px;
  color: var(--vk-ink-2);
}
.warn-icon { color: #f09c40; font-size: 14px; }
.dismiss {
  margin-left: auto;
  background: transparent;
  border: 0;
  color: var(--vk-ink-3);
  cursor: pointer;
  font-size: 14px;
  padding: 0 4px;
}

.catalog-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px 24px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(var(--card-min, 200px), 1fr));
  gap: 10px;
}

/* Strip view — column packing mirrors the collection's CardListPanel so
   the shared --card-width / --strip-height / --strip-expanded vars from
   the global settings drive both at once. Users that tune density in
   settings see the catalog follow. */
.strip-stack {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  gap: 16px;
  width: 100%;
  padding-bottom: var(--strip-expanded);
}
.column {
  display: flex;
  flex-direction: column;
  width: var(--card-width);
  flex-shrink: 0;
  contain: layout style;
}

.empty {
  text-align: center;
  padding: 80px 20px;
  color: var(--vk-ink-3);
  font-style: italic;
}
.empty-hint {
  display: block;
  margin-top: 8px;
  font-family: var(--font-mono), monospace;
  font-style: normal;
  font-size: 11px;
}

.load-more {
  text-align: center;
  padding: 14px;
  color: var(--vk-ink-3);
  font-style: italic;
}
</style>
