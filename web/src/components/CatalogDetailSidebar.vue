<script setup>
import { computed, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import { useDeckStore } from '../stores/deck'
import CardDetailBody from './CardDetailBody.vue'

const catalog = useCatalogStore()
const deck = useDeckStore()

const activeCard = computed(() => catalog.activeCard)

const printings = computed(
  () => catalog.printingsByOracle[catalog.activeCardOracleId] || [],
)
const loading = computed(
  () => !!catalog.printingsLoading[catalog.activeCardOracleId],
)

const selectedPrintingId = computed(
  () => catalog.activePrintings[catalog.activeCardOracleId]
    || activeCard.value?.scryfall_id,
)

const selectedPrinting = computed(
  () => printings.value.find((p) => p.scryfall_id === selectedPrintingId.value) || null,
)

/**
 * The card shape fed to CardDetailBody. If the user picked a different
 * printing than the row's representative, synthesise a card-shaped object
 * from the printings response + the search row's oracle-wide fields
 * (oracle_text, etc.). That way the body shows the right image/set/collector#
 * without losing the oracle-level text.
 */
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
  }
})

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toISOString().slice(0, 10)
}

const deckId = computed(() => deck.deck?.id ?? null)

const adding = ref(false)
async function addToDeck(zone) {
  if (!deckId.value || !selectedPrintingId.value || adding.value) return
  adding.value = true
  try {
    await deck.addEntry(deckId.value, {
      scryfall_id: selectedPrintingId.value,
      zone,
    })
  } catch { /* store-level toast */ } finally {
    adding.value = false
  }
}

const browserOpen = ref(false)
function openBrowser() {
  if (!printings.value.length && !loading.value) {
    catalog.fetchPrintings(catalog.activeCardOracleId)
  }
  browserOpen.value = true
}
function closeBrowser() {
  browserOpen.value = false
}
function pickInBrowser(scryfallId) {
  catalog.pickPrinting(catalog.activeCardOracleId, scryfallId)
  browserOpen.value = false
}

const browserGridStyle = computed(() => {
  const min = catalog.cardSize === 'small' ? 140
    : catalog.cardSize === 'large' ? 280
    : 200
  return { '--browser-card-min': `${min}px` }
})
</script>

<template>
  <aside v-if="activeCard" class="vk-detail vk-detail--catalog">
    <header class="vk-detail-header">
      <button class="close" type="button" @click="catalog.clearActive()" title="Close">✕</button>
    </header>

    <div class="vk-detail-body">
      <CardDetailBody :card="representativeCard" />

      <section v-if="deckId" class="add-actions">
        <h4>Add to deck</h4>
        <div class="add-buttons">
          <button type="button" :disabled="adding" @click="addToDeck('main')">+ Main</button>
          <button type="button" :disabled="adding" @click="addToDeck('side')">+ Side</button>
          <button type="button" :disabled="adding" @click="addToDeck('maybe')">+ Maybe</button>
        </div>
      </section>

      <section class="printings">
        <header class="printings-header">
          <h4>Printings ({{ printings.length }})</h4>
          <button
            type="button"
            class="browse-btn"
            :disabled="loading"
            @click="openBrowser"
            title="Browse printings as card images"
          >Browse images…</button>
        </header>
        <div v-if="loading" class="loading-printings">Loading printings…</div>
        <ul v-else>
          <li
            v-for="p in printings"
            :key="p.scryfall_id"
            class="printing-row"
            :class="{ selected: p.scryfall_id === selectedPrintingId }"
            @click="catalog.pickPrinting(activeCard.oracle_id, p.scryfall_id)"
          >
            <input
              type="radio"
              name="printing"
              :checked="p.scryfall_id === selectedPrintingId"
              :aria-label="p.set_name"
            />
            <img
              v-if="p.icon_svg_uri"
              class="set-icon"
              :src="p.icon_svg_uri"
              :alt="p.set_code"
            />
            <div class="printing-meta">
              <span class="set-name">{{ p.set_name || p.set_code?.toUpperCase() }}</span>
              <span class="set-code-num">
                {{ (p.set_code || '').toUpperCase() }} · #{{ p.collector_number }}
              </span>
              <span class="release">{{ formatDate(p.released_at) }}</span>
            </div>
            <div class="ownership">
              <span v-if="p.ownership?.nonfoil">
                {{ p.ownership.nonfoil }}× ({{ p.ownership.available_nonfoil }} free)
              </span>
              <span v-if="p.ownership?.foil" class="foil">
                {{ p.ownership.foil }}× foil ({{ p.ownership.available_foil }} free)
              </span>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <Teleport to="body">
      <div
        v-if="browserOpen"
        class="printing-browser-backdrop"
        @click.self="closeBrowser"
      >
        <div class="printing-browser" role="dialog" aria-label="Browse printings">
          <header class="browser-header">
            <h3>{{ activeCard?.name }} — pick a printing</h3>
            <button type="button" class="close" @click="closeBrowser" title="Close">✕</button>
          </header>
          <div v-if="loading" class="browser-loading">Loading printings…</div>
          <div v-else class="browser-grid" :style="browserGridStyle">
            <button
              v-for="p in printings"
              :key="p.scryfall_id"
              type="button"
              class="browser-card"
              :class="{ selected: p.scryfall_id === selectedPrintingId }"
              :title="`${p.set_name || p.set_code} · #${p.collector_number}`"
              @click="pickInBrowser(p.scryfall_id)"
            >
              <div class="browser-card-frame">
                <img
                  v-if="p.image_normal || p.image_large"
                  :src="p.image_normal || p.image_large"
                  :alt="`${p.set_name} #${p.collector_number}`"
                  loading="lazy"
                  decoding="async"
                />
                <div v-else class="no-image">{{ p.set_code?.toUpperCase() }} #{{ p.collector_number }}</div>
              </div>
              <footer class="browser-card-meta">
                <img
                  v-if="p.icon_svg_uri"
                  class="set-icon-sm"
                  :src="p.icon_svg_uri"
                  :alt="p.set_code"
                />
                <span class="meta-text">
                  <span class="meta-set">{{ p.set_name || p.set_code?.toUpperCase() }}</span>
                  <span class="meta-num">{{ (p.set_code || '').toUpperCase() }} · #{{ p.collector_number }}</span>
                </span>
              </footer>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </aside>
</template>

<style scoped>
.vk-detail {
  width: var(--detail-width, 360px);
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--bg-1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}

.vk-detail-header {
  padding: 10px 12px 0;
  display: flex;
  justify-content: flex-end;
  flex-shrink: 0;
}
.close {
  background: transparent;
  border: 0;
  color: var(--ink-50);
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  font-size: 14px;
  cursor: pointer;
  padding: 0;
}
.close:hover { background: var(--bg-2); color: var(--ink-100); }

.vk-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.add-actions { margin-top: 20px; }
.add-actions h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 8px;
}
.add-buttons {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}
.add-buttons button {
  background: var(--bg-0);
  border: 1px solid var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  padding: 8px 6px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.1s ease, color 0.1s ease;
}
.add-buttons button:hover:not(:disabled) {
  background: var(--amber-lo);
  color: #1a1408;
}
.add-buttons button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.printings { margin-top: 20px; }
.printings-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin: 0 0 10px;
}
.printings h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0;
}
.browse-btn {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  font-size: 11px;
  padding: 3px 8px;
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.browse-btn:hover:not(:disabled) {
  border-color: var(--amber-lo);
  color: var(--amber);
}
.browse-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.loading-printings {
  font-style: italic;
  color: var(--ink-50);
  font-size: 12px;
  padding: 12px 0;
}
.printings ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.printing-row {
  display: grid;
  grid-template-columns: 16px 24px 1fr auto;
  gap: 10px;
  align-items: center;
  padding: 8px 10px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: border-color 0.1s ease;
}
.printing-row:hover { border-color: var(--amber-lo); }
.printing-row.selected { border-color: var(--amber); background: rgba(201, 165, 82, 0.06); }
.printing-row input[type="radio"] { accent-color: var(--amber); margin: 0; }
.set-icon { width: 24px; height: 24px; object-fit: contain; filter: invert(0.9); }
.printing-meta {
  display: flex;
  flex-direction: column;
  font-size: 12px;
  min-width: 0;
}
.set-name { color: var(--ink-100); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.set-code-num {
  color: var(--ink-50);
  font-family: var(--font-mono), monospace;
  font-size: 10px;
}
.release { color: var(--ink-50); font-size: 10px; }
.ownership {
  display: flex;
  flex-direction: column;
  font-size: 10px;
  color: var(--ink-70);
  text-align: right;
  font-family: var(--font-mono), monospace;
}
.ownership .foil { color: #b898f0; }

/* ─────────────────────────────────────────────────────────────────
   Printings browser modal — full-card images in a scrollable grid.
   Teleported to <body> so the sidebar's overflow:hidden / fixed
   width can't clip it. */
.printing-browser-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px;
}
.printing-browser {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md, 8px);
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.6);
  width: min(1100px, 100%);
  max-height: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.browser-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid var(--hairline);
  flex-shrink: 0;
}
.browser-header h3 {
  margin: 0;
  font-size: 14px;
  color: var(--ink-100);
  font-weight: 600;
}
.browser-header .close {
  background: transparent;
  border: 0;
  color: var(--ink-50);
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 14px;
}
.browser-header .close:hover { background: var(--bg-2); color: var(--ink-100); }

.browser-loading {
  padding: 60px;
  text-align: center;
  color: var(--ink-50);
  font-style: italic;
}

.browser-grid {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(var(--browser-card-min, 200px), 1fr));
  gap: 14px;
  align-content: start;
}
.browser-card {
  background: var(--bg-0);
  border: 2px solid transparent;
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
  display: flex;
  flex-direction: column;
  text-align: left;
  transition: border-color 0.1s ease, transform 0.1s ease;
  overflow: hidden;
  font: inherit;
  color: inherit;
}
.browser-card:hover { border-color: var(--amber-lo); transform: translateY(-2px); }
.browser-card.selected { border-color: var(--amber); }
.browser-card-frame {
  width: 100%;
  aspect-ratio: 63 / 88;
  background: #1a1a22;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
}
.browser-card-frame img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.browser-card-frame .no-image {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: var(--ink-70);
  text-align: center;
  padding: 8px;
  font-family: var(--font-mono), monospace;
  font-size: 12px;
}
.browser-card-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  border-top: 1px solid var(--hairline);
}
.set-icon-sm {
  width: 18px;
  height: 18px;
  object-fit: contain;
  filter: invert(0.9);
  flex-shrink: 0;
}
.meta-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.meta-set {
  font-size: 12px;
  color: var(--ink-100);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.meta-num {
  font-family: var(--font-mono), monospace;
  font-size: 10px;
  color: var(--ink-50);
}
</style>
