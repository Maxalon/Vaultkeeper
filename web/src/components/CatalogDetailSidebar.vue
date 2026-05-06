<script setup>
import { computed, onMounted, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import { useDeckStore } from '../stores/deck'
import { usePricesStore } from '../stores/prices'
import CardDetailBody from './CardDetailBody.vue'
import PriceLine from './PriceLine.vue'
import PrintingPickerModal from './PrintingPickerModal.vue'

const catalog = useCatalogStore()
const deck = useDeckStore()
const pricesStore = usePricesStore()

onMounted(() => {
  // Drive the "updated X ago" caption inside PriceLine. Cheap; cached
  // for an hour by the store.
  pricesStore.fetchStatus().catch(() => {})
})

const activeCard = computed(() => catalog.activeCard)

const printings = computed(
  () => catalog.printingsByOracle[catalog.activeCardOracleId] || [],
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
 *
 * Prices come from the per-printing payload too — each printing has its
 * own Cardmarket trend, so a `set:` swap should swap the price along
 * with the image. Falls back to the search row's prices when the user
 * hasn't picked a different printing.
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
    prices: p.prices ?? row.prices ?? null,
  }
})

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
  browserOpen.value = true
}
function pickInBrowser(scryfallId) {
  catalog.pickPrinting(catalog.activeCardOracleId, scryfallId)
}
</script>

<template>
  <aside v-if="activeCard" class="vk-detail vk-detail--catalog">
    <header class="vk-detail-header">
      <button
        v-if="catalog.activeCardOracleId"
        type="button"
        class="choose-printing-btn"
        @click="openBrowser"
      >Choose printing</button>
      <button class="close" type="button" @click="catalog.clearActive()" title="Close">✕</button>
    </header>

    <div class="vk-detail-body">
      <CardDetailBody :card="representativeCard" />

      <PriceLine :prices="representativeCard?.prices" />

      <section v-if="deckId" class="add-actions">
        <h4>Add to deck</h4>
        <div class="add-buttons">
          <button type="button" :disabled="adding" @click="addToDeck('main')">+ Main</button>
          <button type="button" :disabled="adding" @click="addToDeck('side')">+ Side</button>
          <button type="button" :disabled="adding" @click="addToDeck('maybe')">+ Maybe</button>
        </div>
      </section>
    </div>

    <PrintingPickerModal
      v-model:open="browserOpen"
      :oracle-id="catalog.activeCardOracleId"
      :card-name="activeCard?.name || ''"
      :selected-printing-id="selectedPrintingId"
      @select="pickInBrowser"
    />
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
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
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

.choose-printing-btn {
  flex: 1;
  min-width: 0;
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
.choose-printing-btn:hover {
  background: var(--amber-lo);
  color: #1a1408;
}

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

</style>
