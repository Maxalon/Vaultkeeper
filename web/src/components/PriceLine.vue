<script setup>
import { computed } from 'vue'
import { usePricesStore } from '../stores/prices'
import { formatEur, pickPriceFinish } from '../utils/price'

/**
 * Shared price-row affordance for the three detail sidebars (collection,
 * catalog, deck). Renders nothing when the card has no `prices` payload
 * at all, otherwise shows the finish-aware EUR amount plus a small
 * caption noting the source + last-synced age.
 */
const props = defineProps({
  /** card.prices object — { eur, eur_foil, eur_etched, captured_on } | null */
  prices:    { type: Object, default: null },
  /** Whether to read the foil column instead of nonfoil. */
  foil:      { type: Boolean, default: false },
  /** Whether to read the etched column. Trumps `foil`. */
  isEtched:  { type: Boolean, default: false },
})

const pricesStore = usePricesStore()

const value = computed(() =>
  pickPriceFinish(props.prices, { foil: props.foil, isEtched: props.isEtched }),
)

const lastUpdatedLabel = computed(() => {
  const t = pricesStore.lastSyncedAt
  if (!t) return null
  const ms = Date.now() - Date.parse(t)
  if (Number.isNaN(ms)) return null
  const day = 24 * 60 * 60 * 1000
  if (ms < day) return 'today'
  const d = Math.round(ms / day)
  return `${d} day${d === 1 ? '' : 's'} ago`
})
</script>

<template>
  <section v-if="props.prices" class="vk-price-line">
    <span class="vk-price-amount">≈ {{ formatEur(value) }}</span>
    <span class="vk-price-meta">
      estimated · Cardmarket<span v-if="lastUpdatedLabel"> · updated {{ lastUpdatedLabel }}</span>
    </span>
  </section>
</template>

<style scoped>
.vk-price-line {
  margin-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  background: var(--bg-2, #1d1c1a);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
}
.vk-price-amount {
  font-family: var(--font-display), serif;
  font-size: 18px;
  color: var(--ink-100);
  letter-spacing: -0.01em;
}
.vk-price-meta {
  font-size: 10px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ink-50);
}
</style>
