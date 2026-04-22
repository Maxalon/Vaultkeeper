<script setup>
import { computed, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import CornerCountBadge from './CornerCountBadge.vue'
import DfcPopover from './DfcPopover.vue'

/**
 * Grid tile for a catalog search result row. Click behaviour:
 *   - no deckId → opens the catalog sidebar for this oracle
 *   - deckId set → shows an inline add menu (Main / Side / Maybe),
 *     emits `add-to-deck` with { scryfall_id, zone }
 *
 * Hovering a DFC card teleports a DfcPopover showing the back face.
 * The tile is draggable; its dataTransfer payload is consumed by the
 * DB-3 deck view's drop target.
 */
const props = defineProps({
  card: { type: Object, required: true },
  size: { type: String, default: 'medium' }, // 'small' | 'medium' | 'large'
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['click', 'add-to-deck'])

const catalog = useCatalogStore()

const rootRef = ref(null)
const hovered = ref(false)
const hoverTimer = ref(null)
const addMenuOpen = ref(false)

const effectiveScryfallId = computed(
  () => catalog.activePrintings[props.card.oracle_id] || props.card.scryfall_id,
)

const imageSrc = computed(() => {
  const c = props.card
  if (props.size === 'small') return c.image_small || c.image_normal
  if (props.size === 'large') return c.image_large || c.image_normal
  return c.image_normal || c.image_large || c.image_small
})
const imageSrcset = computed(() => {
  const c = props.card
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
  if (props.deckId !== null) {
    addMenuOpen.value = !addMenuOpen.value
    return
  }
  catalog.setActiveCard(props.card.oracle_id)
  emit('click')
}

function emitAdd(zone) {
  emit('add-to-deck', { scryfall_id: effectiveScryfallId.value, zone })
  addMenuOpen.value = false
}

function onHoverEnter() {
  if (!props.card.is_dfc) return
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
      v-if="card.is_dfc && hovered && card.image_normal_back"
      :back-image="card.image_normal_back"
      :anchor="rootRef"
    />

    <div v-if="deckId && addMenuOpen" class="add-menu" @click.stop>
      <button type="button" @click="emitAdd('main')">+ Main</button>
      <button type="button" @click="emitAdd('side')">+ Side</button>
      <button type="button" @click="emitAdd('maybe')">+ Maybe</button>
    </div>
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
  transition: transform 100ms ease, box-shadow 150ms ease;
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
  color: var(--vk-ink-2);
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

.add-menu {
  position: absolute;
  left: 50%;
  bottom: 10px;
  transform: translateX(-50%);
  display: flex;
  gap: 4px;
  background: rgba(0, 0, 0, 0.82);
  border: 1px solid var(--vk-gold-dim, #8a7436);
  border-radius: 6px;
  padding: 4px;
  z-index: 5;
}
.add-menu button {
  background: transparent;
  border: 0;
  color: var(--vk-gold, #c9a552);
  font-size: 11px;
  font-family: var(--font-mono), monospace;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 3px;
}
.add-menu button:hover { background: rgba(201, 165, 82, 0.18); }
</style>
