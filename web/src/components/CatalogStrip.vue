<script setup>
import { computed, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import { colorSkeletonStyle } from '../composables/useColorSkeleton'
import CornerCountBadge from './CornerCountBadge.vue'
import SetSymbol from './SetSymbol.vue'
import ManaCost from './ManaCost.vue'
import DfcPopover from './DfcPopover.vue'

/**
 * Catalog-view counterpart to CardStrip. Renders one oracle-grouped search
 * result on a compact strip (card-width row, expands on hover to reveal the
 * full card image inline). Reuses the WUBRG skeleton helper but has no
 * collection-entry concerns — no Mode A/B, no peek-popover variant, no
 * quantity/condition editors. The detail sidebar covers the deeper view.
 *
 * Catalog-specific visuals (mirror CardTile):
 *   - Gold badge top-left  = owned_count
 *   - Red badge top-right  = wanted_by_others
 *   - Ownership shine      = green / blue / red border + glow
 *   - DFC hover popover    = back face on ≥300ms hover
 */
const props = defineProps({
  card:   { type: Object, required: true },
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['click', 'add-to-deck'])

const catalog = useCatalogStore()

const rootRef = ref(null)
const imgFailed = ref(false)
const imageLoaded = ref(false)
const hovered = ref(false)
let hoverTimer = null
const addMenuOpen = ref(false)

const hasImage   = computed(() => !!props.card?.image_normal && !imgFailed.value)
const isLoaded   = computed(() => hasImage.value && imageLoaded.value)
const skeletonBg = computed(() => colorSkeletonStyle(props.card?.colors))

const effectiveScryfallId = computed(
  () => catalog.activePrintings[props.card.oracle_id] || props.card.scryfall_id,
)

const shineClass = computed(() => {
  const o = props.card?.owned_count ?? 0
  const a = props.card?.available_count ?? 0
  if (o > 0 && a > 0) return 'shine-green'
  if (o > 0 && a === 0) return 'shine-blue'
  return 'shine-red'
})

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

function onMouseEnter() {
  if (!props.card?.is_dfc) return
  hoverTimer = setTimeout(() => { hovered.value = true }, 300)
}
function onMouseLeave() {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
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
    class="catalog-strip"
    :class="[shineClass, { loaded: isLoaded }]"
    :draggable="true"
    @click="onClick"
    @dragstart="onDragStart"
    @mouseenter="onMouseEnter"
    @mouseleave="onMouseLeave"
  >
    <div class="strip-clip">
      <div class="skeleton" :style="skeletonBg"></div>

      <img
        v-if="hasImage"
        class="card-img"
        :src="card.image_normal"
        :alt="card.name"
        loading="lazy"
        decoding="async"
        @load="imageLoaded = true"
        @error="imgFailed = true"
      />

      <div class="overlay">
        <SetSymbol :set="card.set_code" :rarity="card.rarity || 'common'" :size="16" />
        <span class="name">{{ card.name || '—' }}</span>
        <ManaCost v-if="card.mana_cost" class="cost" :cost="card.mana_cost" />
      </div>

      <CornerCountBadge :count="card.owned_count || 0" />
      <span v-if="card.wanted_by_others > 0" class="badge badge-wanted">{{ card.wanted_by_others }}</span>
    </div>

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
.catalog-strip {
  position: relative;
  width: var(--card-width);
  height: var(--strip-height);
  margin-bottom: 4px;
  /* See DeckCardStrip — percent vertical radius collapses to ~1px at
     strip height; key both radii to card-width to keep the Scryfall
     card curve visible on the top corners. */
  border-radius: calc(var(--card-width) * 0.045);
  cursor: pointer;
  background: var(--bg-2);
  /* Plain dark border — matches real MTG card framing. Focus/hover
     feedback comes from the ownership glow + transform, not from a
     color-changing border. */
  border: 1px solid #0a0a0a;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.45);
  transition: height 160ms ease-out, margin-bottom 160ms ease-out,
              box-shadow 160ms ease, transform 160ms ease-out;
  content-visibility: auto;
  contain-intrinsic-size: auto var(--card-width) auto var(--strip-height);
}
.catalog-strip:hover {
  height: var(--strip-expanded);
  margin-bottom: calc(4px + var(--strip-gap));
  z-index: 2;
  transform: translateY(-1px);
}

/* Ownership shine — backlit halo in the ownership color. Same treatment
   as CardTile, tuned a hair tighter since a strip is a smaller surface. */
.shine-green {
  box-shadow:
    0 0 5px 1px rgba(124, 185, 142, 0.85),
    0 0 16px 4px rgba(124, 185, 142, 0.45);
}
.shine-blue {
  box-shadow:
    0 0 5px 1px rgba(108, 154, 210, 0.85),
    0 0 16px 4px rgba(108, 154, 210, 0.45);
}
.shine-red {
  box-shadow:
    0 0 4px 1px rgba(208, 106, 106, 0.55),
    0 0 12px 3px rgba(208, 106, 106, 0.25);
}
.catalog-strip:hover.shine-green {
  box-shadow:
    0 0 7px 2px rgba(124, 185, 142, 0.95),
    0 0 22px 8px rgba(124, 185, 142, 0.55);
}
.catalog-strip:hover.shine-blue {
  box-shadow:
    0 0 7px 2px rgba(108, 154, 210, 0.95),
    0 0 22px 8px rgba(108, 154, 210, 0.55);
}
.catalog-strip:hover.shine-red {
  box-shadow:
    0 0 6px 2px rgba(208, 106, 106, 0.7),
    0 0 18px 6px rgba(208, 106, 106, 0.35);
}

.strip-clip {
  position: absolute;
  inset: 0;
  overflow: hidden;
  border-radius: inherit;
}

.skeleton {
  position: absolute;
  inset: 0;
  z-index: 0;
}

.card-img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: auto;
  display: block;
  z-index: 1;
  pointer-events: none;
  user-select: none;
}

.overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: var(--strip-height);
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 8px;
  z-index: 2;
  pointer-events: none;
  background: linear-gradient(
    180deg,
    rgba(13, 15, 20, 0) 0%,
    rgba(13, 15, 20, 0.65) 35%,
    rgba(13, 15, 20, 0.92) 100%
  );
  color: var(--text);
  transition: top 200ms ease-out;
}
.catalog-strip.loaded .overlay {
  top: calc(100% - var(--strip-height));
}

.name {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.9);
}
.cost {
  font-size: 14px;
  flex-shrink: 0;
}

.badge {
  position: absolute;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  font-weight: 700;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.45);
  pointer-events: none;
  z-index: 3;
}
.badge-wanted {
  top: 4px;
  right: 4px;
  background: #d06a6a;
  color: #fff;
}

.add-menu {
  position: absolute;
  left: 50%;
  bottom: 8px;
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
