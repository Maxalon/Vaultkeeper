<script setup>
import { computed, ref, watch } from 'vue'
import { useCatalogStore } from '../stores/catalog'

/**
 * Teleported full-card-image grid for picking a printing from an oracle's
 * full printing list. Used by both the catalog sidebar (browse all
 * printings before adding to a deck) and the deck-entry sidebar (swap
 * which printing an unbound slot represents).
 *
 * Shares the catalog store's printings cache so opening the picker on a
 * card the user has already viewed in the catalog is instant.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  oracleId: { type: String, default: null },
  cardName: { type: String, default: '' },
  selectedPrintingId: { type: String, default: null },
})
const emit = defineEmits(['update:open', 'select'])

const catalog = useCatalogStore()

const printings = computed(
  () => (props.oracleId && catalog.printingsByOracle[props.oracleId]) || [],
)
const loading = computed(
  () => !!(props.oracleId && catalog.printingsLoading[props.oracleId]),
)

// Local view-only filter — when on, only printings the user has at
// least one CE for in a non-deck location are shown. Backed by the
// `ownership.in_collection` flag on each row.
const ownedOnly = ref(false)

const visiblePrintings = computed(() =>
  ownedOnly.value
    ? printings.value.filter((p) => p.ownership?.in_collection)
    : printings.value,
)

watch(
  () => [props.open, props.oracleId],
  ([isOpen, id]) => {
    if (isOpen && id && !catalog.printingsByOracle[id] && !catalog.printingsLoading[id]) {
      catalog.fetchPrintings(id)
    }
  },
  { immediate: true },
)

function close() {
  emit('update:open', false)
}

function pick(printing) {
  // Emit (scryfall_id, finishes) so callers can snap a per-slot finish to
  // a value the new printing actually supports. `finishes` is the raw
  // Scryfall array (subset of nonfoil/foil/etched/glossy) or null when the
  // backend hasn't synced it yet.
  emit('select', printing.scryfall_id, printing.finishes ?? null)
  close()
}

const gridStyle = computed(() => {
  const min = catalog.cardSize === 'small' ? 140
    : catalog.cardSize === 'large' ? 280
    : 200
  return { '--browser-card-min': `${min}px` }
})

function imageSrc(p) {
  if (catalog.cardSize === 'small') return p.image_small || p.image_normal || p.image_large
  if (catalog.cardSize === 'large') return p.image_large || p.image_normal || p.image_small
  return p.image_normal || p.image_large || p.image_small
}
function imageSrcset(p) {
  return [
    p.image_small  ? `${p.image_small} 146w`  : null,
    p.image_normal ? `${p.image_normal} 488w` : null,
    p.image_large  ? `${p.image_large} 672w`  : null,
  ].filter(Boolean).join(', ')
}
const imageSizes = computed(() => {
  if (catalog.cardSize === 'small') return '146px'
  if (catalog.cardSize === 'large') return '320px'
  return '220px'
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="printing-browser-backdrop"
      @click.self="close"
    >
      <div class="printing-browser" role="dialog" aria-label="Browse printings">
        <header class="browser-header">
          <h3>{{ cardName }} — pick a printing</h3>
          <label class="owned-toggle">
            <input type="checkbox" v-model="ownedOnly" />
            <span>Owned only</span>
          </label>
          <button type="button" class="close" @click="close" title="Close">✕</button>
        </header>
        <div v-if="loading" class="browser-loading">Loading printings…</div>
        <div v-else-if="!visiblePrintings.length" class="browser-loading">
          {{ ownedOnly ? 'You don’t own any printings of this card.' : 'No printings found.' }}
        </div>
        <div v-else class="browser-grid" :style="gridStyle">
          <button
            v-for="p in visiblePrintings"
            :key="p.scryfall_id"
            type="button"
            class="browser-card"
            :class="{ selected: p.scryfall_id === selectedPrintingId }"
            :title="`${p.set_name || p.set_code} · #${p.collector_number}`"
            @click="pick(p)"
          >
            <div class="browser-card-frame">
              <img
                v-if="imageSrc(p)"
                :src="imageSrc(p)"
                :srcset="imageSrcset(p)"
                :sizes="imageSizes"
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
</template>

<style scoped>
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
  gap: 14px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--hairline);
  flex-shrink: 0;
}
.browser-header h3 {
  margin: 0;
  font-size: 14px;
  color: var(--ink-100);
  font-weight: 600;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.owned-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--ink-70);
  cursor: pointer;
  user-select: none;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.owned-toggle input { accent-color: var(--amber); margin: 0; }
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
  grid-auto-rows: max-content;
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
  position: relative;
  background: #1a1a22;
  border: 1px solid #0a0a0a;
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
}
.browser-card-frame::before {
  content: '';
  display: block;
  padding-top: 139.68%; /* 88/63 — reserves height before the image loads */
}
.browser-card-frame img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: inherit;
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
