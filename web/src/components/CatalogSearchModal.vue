<script setup>
import { computed, ref, watch } from 'vue'
import api from '../lib/api'
import { useDeckStore } from '../stores/deck'
import { usePricesStore } from '../stores/prices'
import SyntaxSearch from './SyntaxSearch.vue'
import ManaCost from './ManaCost.vue'
import CardDetailBody from './CardDetailBody.vue'
import PriceLine from './PriceLine.vue'
import PrintingPickerModal from './PrintingPickerModal.vue'

/**
 * Self-contained catalog search modal for deck building. Provides the same
 * search + preview + add-to-deck flow as CatalogPanel + CatalogDetailSidebar
 * but in a teleported overlay that doesn't depend on the tab layout.
 *
 * Maintains its own search state so opening this modal doesn't disturb
 * the catalog tab's results or active-card selection.
 */
const props = defineProps({
  open:   { type: Boolean, default: false },
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['update:open'])

const deck = useDeckStore()
const pricesStore = usePricesStore()

// ── Search state ─────────────────────────────────────────────────────────────

const query = ref('')
const results = ref([])
const total = ref(0)
const loading = ref(false)
const warnings = ref([])
const warningsDismissed = ref(false)
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)

let inflightCtrl = null
let latestReqId = 0

const showWarnings = computed(() => warnings.value.length > 0 && !warningsDismissed.value)

const needsMoreInput = computed(() => {
  const len = query.value.trim().length
  return len === 1 || len === 0
})

async function search(q) {
  const trimmed = (q || '').trim()
  if (trimmed.length < 2) {
    results.value = []
    total.value = 0
    warnings.value = []
    return
  }

  if (inflightCtrl) inflightCtrl.abort()
  inflightCtrl = new AbortController()
  const ctrl = inflightCtrl
  const reqId = ++latestReqId

  page.value = 1
  warningsDismissed.value = false
  selectedOracleId.value = null
  printingPickerOpen.value = false

  loading.value = true
  try {
    const params = { q, per_page: 40, page: 1 }
    if (props.deckId) params.deck_id = props.deckId
    const { data } = await api.get('/scryfall-cards/search', {
      params,
      signal: ctrl.signal,
    })
    if (reqId !== latestReqId) return
    results.value = data.data
    total.value = data.total
    lastPage.value = data.last_page
    warnings.value = data.warnings || []
  } catch (e) {
    if (e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
    throw e
  } finally {
    if (reqId === latestReqId) loading.value = false
  }
}

async function loadMore() {
  if (loadingMore.value || page.value >= lastPage.value) return
  const reqId = latestReqId
  loadingMore.value = true
  try {
    const nextPage = page.value + 1
    const params = { q: query.value, per_page: 40, page: nextPage }
    if (props.deckId) params.deck_id = props.deckId
    const { data } = await api.get('/scryfall-cards/search', { params })
    if (reqId !== latestReqId) return
    results.value.push(...data.data)
    page.value = nextPage
    lastPage.value = data.last_page
  } finally {
    loadingMore.value = false
  }
}

function onResultsScroll(e) {
  const el = e.target
  if (!el) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 300) loadMore()
}

let debounceTimer = null
watch(query, (v) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => search(v), 300)
})

// ── Detail / printing state ───────────────────────────────────────────────────

const selectedOracleId = ref(null)
const printingsByOracle = ref({})
const printingsLoadingByOracle = ref({})
const selectedPrintingByOracle = ref({})

const activeCard = computed(() =>
  selectedOracleId.value
    ? results.value.find((r) => r.oracle_id === selectedOracleId.value) || null
    : null,
)

const printings = computed(
  () => (selectedOracleId.value && printingsByOracle.value[selectedOracleId.value]) || [],
)

const selectedPrintingId = computed(
  () => selectedPrintingByOracle.value[selectedOracleId.value]
    || activeCard.value?.scryfall_id,
)

const selectedPrinting = computed(
  () => printings.value.find((p) => p.scryfall_id === selectedPrintingId.value) || null,
)

const representativeCard = computed(() => {
  const row = activeCard.value
  if (!row) return null
  const p = selectedPrinting.value
  if (!p || p.scryfall_id === row.scryfall_id) return row
  return {
    ...row,
    scryfall_id: p.scryfall_id,
    set_code: p.set_code,
    collector_number: p.collector_number,
    rarity: p.rarity,
    image_small: p.image_small,
    image_normal: p.image_normal,
    image_large: p.image_large,
    image_small_back: p.image_small_back,
    image_normal_back: p.image_normal_back,
    image_large_back: p.image_large_back,
    prices: p.prices ?? row.prices ?? null,
  }
})

async function fetchPrintings(oracleId) {
  if (printingsByOracle.value[oracleId] || printingsLoadingByOracle.value[oracleId]) return
  printingsLoadingByOracle.value = { ...printingsLoadingByOracle.value, [oracleId]: true }
  try {
    const { data } = await api.get('/scryfall-cards/printings', {
      params: { oracle_id: oracleId },
    })
    printingsByOracle.value = { ...printingsByOracle.value, [oracleId]: data.data }
  } finally {
    const next = { ...printingsLoadingByOracle.value }
    delete next[oracleId]
    printingsLoadingByOracle.value = next
  }
}

function selectCard(oracleId) {
  selectedOracleId.value = oracleId
  fetchPrintings(oracleId)
  pricesStore.fetchStatus().catch(() => {})
}

function pickPrinting(scryfallId) {
  if (!selectedOracleId.value) return
  selectedPrintingByOracle.value = {
    ...selectedPrintingByOracle.value,
    [selectedOracleId.value]: scryfallId,
  }
}

// ── Printing picker modal ─────────────────────────────────────────────────────

const printingPickerOpen = ref(false)

// ── Add-to-deck ───────────────────────────────────────────────────────────────

const adding = ref(false)
async function addToDeck(zone) {
  if (!props.deckId || !selectedPrintingId.value || adding.value) return
  adding.value = true
  try {
    await deck.addEntry(props.deckId, { scryfall_id: selectedPrintingId.value, zone })
  } catch { /* deck store shows toast */ } finally {
    adding.value = false
  }
}

// ── Syntax help ───────────────────────────────────────────────────────────────

const showHelp = ref(false)

// ── Lifecycle / reset ─────────────────────────────────────────────────────────

watch(() => props.open, (isOpen) => {
  if (!isOpen) {
    if (inflightCtrl) inflightCtrl.abort()
    query.value = ''
    results.value = []
    total.value = 0
    warnings.value = []
    selectedOracleId.value = null
    printingsByOracle.value = {}
    printingsLoadingByOracle.value = {}
    selectedPrintingByOracle.value = {}
    printingPickerOpen.value = false
    showHelp.value = false
  }
})

function close() {
  emit('update:open', false)
}

function onBackdropClick() {
  if (!printingPickerOpen.value) close()
}

// Close on Escape, unless the printing picker is absorbing it.
function onKeydown(e) {
  if (e.key === 'Escape' && !printingPickerOpen.value) close()
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="csm-backdrop"
      @click.self="onBackdropClick"
      @keydown="onKeydown"
      tabindex="-1"
    >
      <div class="csm-shell" role="dialog" aria-label="Card search">
        <!-- Header -->
        <header class="csm-header">
          <div class="csm-search-row">
            <SyntaxSearch
              v-model="query"
              placeholder="Scryfall syntax: t:creature c:g cmc<=3…"
              @help="showHelp = !showHelp"
            />
            <span class="csm-total" v-if="!loading">
              {{ total > 0 ? total.toLocaleString() + ' found' : '' }}
            </span>
            <span class="csm-total csm-total--loading" v-else>searching…</span>
          </div>
          <div v-if="showWarnings" class="csm-warnings">
            <span class="csm-warn-icon">⚠</span>
            <span>Ignored: {{ warnings.join('; ') }}</span>
            <button class="csm-dismiss" type="button" @click="warningsDismissed = true">×</button>
          </div>
          <button class="csm-close" type="button" @click="close" title="Close">✕</button>
        </header>

        <!-- Body: results list + detail pane -->
        <div class="csm-body">
          <!-- Results list -->
          <div class="csm-results" @scroll.passive="onResultsScroll">
            <div v-if="!loading && results.length === 0" class="csm-empty">
              <template v-if="needsMoreInput">
                Type at least 2 characters to search.
              </template>
              <template v-else>
                No cards match this search.
              </template>
            </div>

            <ul v-else class="csm-list">
              <li
                v-for="card in results"
                :key="card.oracle_id"
                class="csm-row"
                :class="{ 'csm-row--active': card.oracle_id === selectedOracleId }"
                @click="selectCard(card.oracle_id)"
              >
                <div class="csm-row-name">{{ card.name }}</div>
                <ManaCost class="csm-row-cost" :cost="card.mana_cost || ''" />
                <div class="csm-row-type">{{ card.type_line }}</div>
                <img
                  v-if="card.icon_svg_uri"
                  class="csm-row-set-icon"
                  :src="card.icon_svg_uri"
                  :alt="card.set_code"
                  :title="card.set_name || card.set_code"
                />
              </li>
            </ul>

            <div v-if="loadingMore" class="csm-load-more">Loading more…</div>
          </div>

          <!-- Detail pane -->
          <div class="csm-detail" v-if="activeCard">
            <div class="csm-detail-actions">
              <button
                type="button"
                class="csm-printing-btn"
                @click="printingPickerOpen = true"
              >Choose printing</button>
            </div>

            <div class="csm-detail-body">
              <CardDetailBody :card="representativeCard" />
              <PriceLine :prices="representativeCard?.prices" />

              <section v-if="deckId" class="csm-add-section">
                <h4 class="csm-add-label">Add to deck</h4>
                <div class="csm-add-buttons">
                  <button type="button" :disabled="adding" @click="addToDeck('main')">+ Main</button>
                  <button type="button" :disabled="adding" @click="addToDeck('side')">+ Side</button>
                  <button type="button" :disabled="adding" @click="addToDeck('maybe')">+ Maybe</button>
                </div>
              </section>
            </div>
          </div>

          <!-- Syntax help panel -->
          <div v-else-if="showHelp" class="csm-help">
            <h3 class="csm-help-title">Scryfall syntax reference</h3>
            <dl class="csm-help-list">
              <dt>name:</dt>        <dd>Card name · <code>name:lightning</code> <code>!"Lightning Bolt"</code></dd>
              <dt>t:</dt>          <dd>Type line · <code>t:creature</code> <code>t:"legendary creature"</code></dd>
              <dt>o:</dt>          <dd>Oracle text · <code>o:flying</code></dd>
              <dt>fo:</dt>         <dd>Full oracle (incl. reminder) · <code>fo:trample</code></dd>
              <dt>c:</dt>          <dd>Color · <code>c:g</code> <code>c:wu</code> <code>c:colorless</code></dd>
              <dt>ci:</dt>         <dd>Color identity · <code>ci:gw</code></dd>
              <dt>m:</dt>          <dd>Mana cost · <code>m:{G}{G}</code></dd>
              <dt>cmc: / mv:</dt>  <dd>Mana value · <code>cmc&lt;=3</code> <code>mv=2</code></dd>
              <dt>pow: / tou:</dt> <dd>Power / toughness · <code>pow&gt;=4</code></dd>
              <dt>loy:</dt>        <dd>Loyalty · <code>loy=3</code></dd>
              <dt>r:</dt>          <dd>Rarity · <code>r:rare</code> <code>r:mythic</code></dd>
              <dt>s:</dt>          <dd>Set code · <code>s:dmu</code></dd>
              <dt>cn:</dt>         <dd>Collector number · <code>cn:100</code></dd>
              <dt>f:</dt>          <dd>Format legality · <code>f:commander</code> <code>f:modern</code></dd>
              <dt>kw:</dt>         <dd>Keyword · <code>kw:flying</code> <code>kw:trample</code></dd>
              <dt>is:</dt>         <dd>
                Card category ·
                <code>is:commander</code>
                <code>is:partner</code>
                <code>is:dfc</code>
                <code>is:companion</code>
                <code>is:reserved</code>
                <code>is:gc</code>
              </dd>
              <dt>order:</dt>      <dd>Sort · <code>order:cmc</code> <code>order:edhrec</code> <code>order:rarity</code></dd>
              <dt>direction:</dt>  <dd>Sort direction · <code>direction:asc</code> <code>direction:desc</code></dd>
            </dl>
            <p class="csm-help-note">Prefix any operator with <code>-</code> to negate it. Use <code>OR</code> between terms.</p>
          </div>
        </div>
      </div>
    </div>

    <PrintingPickerModal
      v-model:open="printingPickerOpen"
      :oracle-id="selectedOracleId"
      :card-name="activeCard?.name || ''"
      :selected-printing-id="selectedPrintingId"
      @select="pickPrinting"
    />
  </Teleport>
</template>

<style scoped>
.csm-backdrop {
  position: fixed;
  inset: 0;
  z-index: 900;
  background: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px;
}

.csm-shell {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md, 8px);
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.6);
  width: min(1000px, 100%);
  max-height: min(800px, 90vh);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.csm-header {
  flex-shrink: 0;
  padding: 12px 16px 10px;
  border-bottom: 1px solid var(--hairline);
  display: flex;
  flex-direction: column;
  gap: 6px;
  position: relative;
}

.csm-search-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-right: 36px; /* room for close button */
}
.csm-search-row :deep(.vk-syntax-search) { flex: 1; }

.csm-total {
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  color: var(--ink-50);
  white-space: nowrap;
}
.csm-total--loading { color: var(--amber-lo); }

.csm-warnings {
  display: flex;
  gap: 10px;
  align-items: center;
  background: rgba(251, 146, 60, 0.08);
  border: 1px solid rgba(251, 146, 60, 0.3);
  border-radius: var(--radius-sm);
  padding: 5px 10px;
  font-size: 12px;
  color: var(--ink-70);
}
.csm-warn-icon { color: #f09c40; }
.csm-dismiss {
  margin-left: auto;
  background: transparent;
  border: 0;
  color: var(--ink-50);
  cursor: pointer;
  font-size: 14px;
  padding: 0 4px;
}

.csm-close {
  position: absolute;
  top: 12px;
  right: 12px;
  background: transparent;
  border: 0;
  color: var(--ink-50);
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.csm-close:hover { background: var(--bg-2); color: var(--ink-100); }

/* Body */
.csm-body {
  flex: 1;
  min-height: 0;
  display: flex;
  overflow: hidden;
}

/* Results list */
.csm-results {
  flex: 1;
  min-width: 0;
  overflow-y: auto;
  padding: 8px 0;
}

.csm-empty {
  padding: 60px 20px;
  text-align: center;
  color: var(--ink-50);
  font-style: italic;
}

.csm-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.csm-row {
  display: grid;
  grid-template-columns: 1fr auto auto auto;
  align-items: center;
  gap: 8px;
  padding: 7px 16px;
  cursor: pointer;
  border-bottom: 1px solid var(--hairline);
  transition: background 0.08s ease;
}
.csm-row:hover { background: var(--bg-2); }
.csm-row--active { background: var(--bg-2); outline: 2px solid var(--amber-lo); outline-offset: -2px; }

.csm-row-name {
  font-size: 13px;
  color: var(--ink-100);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.csm-row-cost {
  flex-shrink: 0;
}
.csm-row-type {
  font-size: 11px;
  color: var(--ink-50);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 200px;
}
.csm-row-set-icon {
  width: 14px;
  height: 14px;
  object-fit: contain;
  filter: invert(0.9);
  flex-shrink: 0;
}

.csm-load-more {
  text-align: center;
  padding: 12px;
  color: var(--ink-50);
  font-style: italic;
  font-size: 12px;
}

/* Detail pane */
.csm-detail {
  width: 340px;
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--bg-0);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.csm-detail-actions {
  flex-shrink: 0;
  padding: 10px 12px;
  border-bottom: 1px solid var(--hairline);
}

.csm-printing-btn {
  width: 100%;
  background: var(--bg-1);
  border: 1px solid var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.1s ease, color 0.1s ease;
}
.csm-printing-btn:hover {
  background: var(--amber-lo);
  color: #1a1408;
}

.csm-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 16px 24px;
}

.csm-add-section { margin-top: 20px; }
.csm-add-label {
  margin: 0 0 8px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
}
.csm-add-buttons {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}
.csm-add-buttons button {
  background: var(--bg-1);
  border: 1px solid var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  padding: 8px 6px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.1s ease, color 0.1s ease;
}
.csm-add-buttons button:hover:not(:disabled) {
  background: var(--amber-lo);
  color: #1a1408;
}
.csm-add-buttons button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Syntax help panel */
.csm-help {
  width: 340px;
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--bg-0);
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.csm-help-title {
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-100);
}

.csm-help-list {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 12px;
  margin: 0 0 14px;
  font-size: 12px;
}
.csm-help-list dt {
  font-family: var(--font-mono), monospace;
  color: var(--amber, #c9a552);
  white-space: nowrap;
  padding-top: 1px;
}
.csm-help-list dd {
  margin: 0;
  color: var(--ink-70);
  line-height: 1.5;
}
.csm-help-list dd code {
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  background: var(--bg-2);
  border-radius: 3px;
  padding: 1px 4px;
  color: var(--ink-100);
  white-space: nowrap;
}

.csm-help-note {
  font-size: 11px;
  color: var(--ink-50);
  margin: 0;
}
.csm-help-note code {
  font-family: var(--font-mono), monospace;
  background: var(--bg-2);
  border-radius: 3px;
  padding: 1px 4px;
  color: var(--ink-100);
}
</style>
