<script setup>
import { computed, watch } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import CardDetailBody from './CardDetailBody.vue'

const catalog = useCatalogStore()

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
  const p = printings.value.find((p) => p.scryfall_id === selectedPrintingId.value)
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

// Open the sidebar whenever activeCardOracleId changes — the store
// already kicks off the printings fetch on that transition.
watch(() => catalog.activeCardOracleId, () => {})
</script>

<template>
  <aside v-if="activeCard" class="vk-detail vk-detail--catalog">
    <header class="vk-detail-header">
      <button class="close" type="button" @click="catalog.clearActive()" title="Close">✕</button>
    </header>

    <div class="vk-detail-body">
      <CardDetailBody :card="representativeCard" />

      <section class="printings">
        <h4>Printings ({{ printings.length }})</h4>
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

.printings { margin-top: 20px; }
.printings h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 10px;
}
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
</style>
