<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import {
  costPipsByColor,
  producerCardsByColor,
  cmcBuckets,
  avgManaValue,
  totalManaValue,
  COLOR_ORDER,
} from '../../utils/deckStats'
import ManaSymbol from '../ManaSymbol.vue'

const deck = useDeckStore()

const mainEntries = computed(() => deck.entriesByZone('main'))

const costs = computed(() => costPipsByColor(mainEntries.value))
const producers = computed(() => producerCardsByColor(mainEntries.value))
const buckets = computed(() => cmcBuckets(mainEntries.value))
const avg = computed(() => avgManaValue(mainEntries.value))
const total = computed(() => totalManaValue(mainEntries.value))

function pct(val, sum) {
  if (!sum) return 0
  return Math.round((val / sum) * 100)
}

const costTotal = computed(() =>
  Object.values(costs.value).reduce((a, b) => a + b, 0),
)
const producerTotal = computed(() =>
  Object.values(producers.value).reduce((a, b) => a + b, 0),
)
const maxBucket = computed(() =>
  Math.max(1, ...Object.values(buckets.value)),
)
</script>

<template>
  <div class="analysis-tab">
    <h3>Mana breakdown</h3>

    <div class="analysis-row">
      <div class="analysis-col">
        <h4>Cost pips</h4>
        <div v-for="c in COLOR_ORDER" :key="'cost-'+c" class="bar-row">
          <ManaSymbol :symbol="`{${c}}`" />
          <div class="bar">
            <div class="bar-fill" :style="{ width: pct(costs[c], costTotal) + '%' }" />
          </div>
          <span class="bar-count">{{ costs[c].toFixed(1).replace('.0','') }}</span>
        </div>
      </div>

      <div class="analysis-col">
        <h4>Production</h4>
        <div v-for="c in COLOR_ORDER" :key="'prod-'+c" class="bar-row">
          <ManaSymbol :symbol="`{${c}}`" />
          <div class="bar">
            <div class="bar-fill" :style="{ width: pct(producers[c], producerTotal) + '%' }" />
          </div>
          <span class="bar-count">{{ producers[c] }}</span>
        </div>
      </div>
    </div>

    <h3>Mana curve</h3>
    <div class="curve-chart">
      <div
        v-for="(count, bucket) in buckets"
        :key="bucket"
        class="curve-col"
      >
        <span class="curve-count">{{ count }}</span>
        <div
          class="curve-bar"
          :style="{ height: (count / maxBucket) * 100 + '%' }"
        />
        <span class="curve-label">{{ bucket }}</span>
      </div>
    </div>
    <div class="avg-caption">
      Avg mana value: {{ avg.toFixed(2) }}
      · Total mana value: {{ total }}
    </div>
  </div>
</template>

<style scoped>
.analysis-tab {
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
h3 { margin: 0; font-size: 1rem; }
h4 { margin: 0 0 0.4rem 0; font-size: 0.8rem; color: var(--ink-70, #a8a396); text-transform: uppercase; letter-spacing: 0.05em; }
.analysis-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
.bar-row {
  display: grid;
  grid-template-columns: 24px 1fr 40px;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 4px;
}
.bar {
  background: var(--bg-2, #26241f);
  border-radius: 3px;
  height: 14px;
  overflow: hidden;
}
.bar-fill {
  background: var(--amber, #c99d3d);
  height: 100%;
  transition: width 150ms ease;
}
.bar-count {
  font-size: 0.8rem;
  text-align: right;
  color: var(--ink-70, #a8a396);
}
.curve-chart {
  display: flex;
  align-items: flex-end;
  gap: 4px;
  height: 160px;
  border-bottom: 1px solid var(--hairline, #33312c);
  padding: 0 0 0.5rem 0;
}
.curve-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  height: 100%;
}
.curve-count {
  font-size: 0.75rem;
  color: var(--ink-70, #a8a396);
  margin-bottom: 2px;
}
.curve-bar {
  width: 70%;
  background: var(--amber, #c99d3d);
  border-radius: 2px 2px 0 0;
  min-height: 2px;
  transition: height 150ms ease;
}
.curve-label {
  margin-top: 4px;
  font-size: 0.75rem;
  color: var(--ink-70, #a8a396);
}
.avg-caption {
  font-size: 0.85rem;
  color: var(--ink-70, #a8a396);
}
</style>
