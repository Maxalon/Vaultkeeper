<script setup>
import { computed } from 'vue'
import { useCollectionStore } from '../stores/collection'

const collection = useCollectionStore()

const uniqueCount = computed(() => collection.filteredEntries.length)
const totalCount = computed(() =>
  collection.filteredEntries.reduce((sum, e) => sum + (Number(e.quantity) || 1), 0),
)

// Pluck the newest created_at across the loaded entries (raw — not the
// filtered view, so the value reflects the actual collection state).
const lastAdded = computed(() => {
  let max = 0
  for (const e of collection.entries) {
    if (!e.created_at) continue
    const t = Date.parse(e.created_at)
    if (!Number.isNaN(t) && t > max) max = t
  }
  return max || null
})

const lastAddedLabel = computed(() => {
  if (!lastAdded.value) return '—'
  const diffMs = Date.now() - lastAdded.value
  const min = 60 * 1000
  const hr = 60 * min
  const day = 24 * hr
  if (diffMs < min) return 'just now'
  if (diffMs < hr) return `${Math.round(diffMs / min)} min ago`
  if (diffMs < day) return `${Math.round(diffMs / hr)} hr ago`
  if (diffMs < 30 * day) return `${Math.round(diffMs / day)} days ago`
  if (diffMs < 365 * day) return `${Math.round(diffMs / (30 * day))} mo ago`
  return `${Math.round(diffMs / (365 * day))} yr ago`
})

const showingLabel = computed(() => {
  const f = collection.filteredEntries.length
  const t = collection.entries.length
  if (t === 0) return '0'
  if (f === t) return `${f}`
  return `${f} of ${t}`
})
</script>

<template>
  <div class="vk-stats-bar">
    <div class="stat">
      <span class="k">Unique</span>
      <span class="v">{{ uniqueCount.toLocaleString() }}</span>
    </div>
    <div class="stat">
      <span class="k">Total</span>
      <span class="v">{{ totalCount.toLocaleString() }}</span>
    </div>
    <div class="stat">
      <span class="k">Last Added</span>
      <span class="v compact">{{ lastAddedLabel }}</span>
    </div>
    <div class="grow" />
    <div class="stat" style="align-items: flex-end;">
      <span class="k">Showing</span>
      <span class="v compact">{{ showingLabel }}</span>
    </div>
  </div>
</template>

<style scoped>
.vk-stats-bar {
  display: flex;
  align-items: stretch;
  background: var(--vk-bg-1);
  border-bottom: 1px solid var(--vk-line);
  flex-shrink: 0;
}
.stat {
  padding: 10px 20px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 120px;
  position: relative;
}
.stat + .stat::before {
  content: '';
  position: absolute;
  left: 0;
  top: 25%;
  bottom: 25%;
  width: 1px;
  background: var(--vk-line-soft);
}
.k {
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vk-ink-3);
}
.v {
  font-family: var(--font-display);
  font-size: 20px;
  font-weight: 500;
  color: var(--vk-ink-1);
  letter-spacing: -0.01em;
}
.v.compact {
  font-family: var(--font-sans);
  font-size: 13px;
  color: var(--vk-ink-2);
}
.grow { flex: 1; }
.stat:last-child::before { display: none; }
</style>
