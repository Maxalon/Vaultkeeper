<script setup>
import { computed, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import CornerCountBadge from './CornerCountBadge.vue'
import DfcPopover from './DfcPopover.vue'

/**
 * Grid tile for a catalog search result row. Click opens the catalog
 * detail sidebar; the sidebar hosts the printing picker and the
 * Main/Side/Maybe add-to-deck buttons.
 *
 * Hovering a DFC card teleports a DfcPopover showing the back face.
 * The tile is draggable; its dataTransfer payload is consumed by the
 * deck view's drop target.
 */
const props = defineProps({
  card: { type: Object, required: true },
  size: { type: String, default: 'medium' }, // 'small' | 'medium' | 'large'
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['click'])

const catalog = useCatalogStore()

const rootRef = ref(null)
const hovered = ref(false)
const hoverTimer = ref(null)

const effectiveScryfallId = computed(
  () => catalog.activePrintings[props.card.oracle_id] || props.card.scryfall_id,
)

// When the user has picked a non-default printing, swap the tile's image
// (and DFC back face) to that printing so the catalog visibly reflects
// the choice. The selection lives in catalog.activePrintings and is wiped
// on the next search, so this is purely temporary state.
const selectedPrinting = computed(() => {
  const sid = catalog.activePrintings[props.card.oracle_id]
  if (!sid || sid === props.card.scryfall_id) return null
  const list = catalog.printingsByOracle[props.card.oracle_id] || []
  return list.find((p) => p.scryfall_id === sid) || null
})

const displayCard = computed(() => {
  const p = selectedPrinting.value
  if (!p) return props.card
  return {
    ...props.card,
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

const imageSrc = computed(() => {
  const c = displayCard.value
  if (props.size === 'small') return c.image_small || c.image_normal
  if (props.size === 'large') return c.image_large || c.image_normal
  return c.image_normal || c.image_large || c.image_small
})
const imageSrcset = computed(() => {
  const c = displayCard.value
  return [
    c.image_small  ? `${c.image_small} 146w`  : null,
    c.image_normal ? `${c.image_normal} 488w` : null,
    c.image_large  ? `${c.image_large} 672w`  : null,
  ].filter(Boolean).join(', ')
})
const imageSizes = computed(() => {
  if (props.size === 'small') return '146px'
  if (props.size === 'large') return '320px'
  return '220px'
})

const shineClass = computed(() => {
  const o = props.card.owned_count
  const a = props.card.available_count
  if (o > 0 && a > 0) return 'shine-green'
  if (o > 0 && a === 0) return 'shine-blue'
  return 'shine-red'
})

const sizeClass = computed(() => `size-${props.size}`)

function onClick() {
  catalog.setActiveCard(props.card.oracle_id)
  emit('click')
}

function onHoverEnter() {
  if (!displayCard.value.is_dfc) return
  hoverTimer.value = setTimeout(() => { hovered.value = true }, 300)
}
function onHoverLeave() {
  if (hoverTimer.value) { clearTimeout(hoverTimer.value); hoverTimer.value = null }
  hovered.value = false
}

function onDragStart(e) {
  e.dataTransfer.setData('application/json', JSON.stringify({
    oracle_id: props.card.oracle_id,
    scryfall_id: effectiveScryfallId.value,
    source: 'catalog',
  }))
  // Sentinel MIME — DeckGrid reads .types during dragover (.getData isn't
  // available there) to set dropEffect='copy'. Without this the browser
  // refuses the drop because effectAllowed='copy' doesn't match 'move'.
  e.dataTransfer.setData('application/x-vk-catalog', '1')
  e.dataTransfer.effectAllowed = 'copy'
}
</script>

<template>
  <div
    ref="rootRef"
    class="card-tile"
    :class="[sizeClass, shineClass]"
    :draggable="true"
    @click="onClick"
    @dragstart="onDragStart"
    @mouseenter="onHoverEnter"
    @mouseleave="onHoverLeave"
  >
    <img
      v-if="imageSrc"
      :src="imageSrc"
      :srcset="imageSrcset"
      :sizes="imageSizes"
      :alt="card.name"
      loading="lazy"
      decoding="async"
    />
    <div v-else class="tile-name-fallback">{{ card.name }}</div>

    <CornerCountBadge :count="card.owned_count || 0" />
    <span v-if="card.wanted_by_others > 0" class="badge badge-wanted">{{ card.wanted_by_others }}</span>

    <DfcPopover
      v-if="displayCard.is_dfc && hovered && displayCard.image_normal_back"
      :back-image="displayCard.image_normal_back"
      :anchor="rootRef"
    />
  </div>
</template>

<style scoped>
.card-tile {
  position: relative;
  aspect-ratio: 63 / 88;
  border-radius: 8px;
  /* Inner content is clipped to the rounded corners; the ownership
     box-shadow glow is NOT clipped by overflow (CSS applies it outside
     the border box), so the backlit halo reads cleanly around the card. */
  overflow: hidden;
  cursor: pointer;
  /* Plain dark border — matches real MTG card framing. Accent/hover
     signalling comes from the ownership glow + transform, not from a
     color-changing border (avoids fighting with the shine colors). */
  border: 1px solid #0a0a0a;
  background: #1a1a22;
  /* Native virtualization: skip layout/paint for off-screen tiles. */
  content-visibility: auto;
  contain-intrinsic-size: auto 340px;
  /* box-shadow is deliberately NOT transitioned. A blurred shadow has
     to be re-rasterised every frame of a transition, which tanks paint
     budget when 60+ shining tiles are visible. Snapping on hover keeps
     the visual and removes the per-frame paint. */
  transition: transform 100ms ease;
}
.card-tile:hover {
  transform: translateY(-2px);
  z-index: 2;
}
.card-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: inherit;
}
.tile-name-fallback {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-70);
  text-align: center;
  padding: 10px;
  font-family: var(--font-display), serif;
  font-size: 14px;
}

/* Ownership shine — a backlit halo behind the card. Double box-shadow
   gives a tight inner ring for presence and a wider outer bloom that
   reads as light escaping around the edges. Border stays gold. */
.shine-green {
  box-shadow:
    0 0 6px 1px rgba(124, 185, 142, 0.85),
    0 0 20px 6px rgba(124, 185, 142, 0.45);
}
.shine-blue {
  box-shadow:
    0 0 6px 1px rgba(108, 154, 210, 0.85),
    0 0 20px 6px rgba(108, 154, 210, 0.45);
}
.shine-red {
  box-shadow:
    0 0 5px 1px rgba(208, 106, 106, 0.55),
    0 0 16px 5px rgba(208, 106, 106, 0.25);
}

.card-tile:hover.shine-green {
  box-shadow:
    0 0 8px 2px rgba(124, 185, 142, 0.95),
    0 0 28px 10px rgba(124, 185, 142, 0.55);
}
.card-tile:hover.shine-blue {
  box-shadow:
    0 0 8px 2px rgba(108, 154, 210, 0.95),
    0 0 28px 10px rgba(108, 154, 210, 0.55);
}
.card-tile:hover.shine-red {
  box-shadow:
    0 0 7px 2px rgba(208, 106, 106, 0.7),
    0 0 22px 8px rgba(208, 106, 106, 0.35);
}

.size-small  { contain-intrinsic-size: auto 200px; }
.size-medium { contain-intrinsic-size: auto 340px; }
.size-large  { contain-intrinsic-size: auto 460px; }

.badge {
  position: absolute;
  min-width: 24px;
  height: 24px;
  padding: 0 6px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  font-weight: 700;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
  pointer-events: none;
}
.badge-wanted {
  top: 6px;
  right: 6px;
  background: #d06a6a;
  color: #fff;
}

</style>
