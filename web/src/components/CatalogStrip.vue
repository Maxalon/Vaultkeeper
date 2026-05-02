<script setup>
import { computed, ref } from 'vue'
import { useCatalogStore } from '../stores/catalog'
import BaseCardStrip from './strip/BaseCardStrip.vue'

/**
 * Catalog-view counterpart. Thin wrapper over BaseCardStrip. Renders
 * one oracle-grouped search result as a compact strip.
 *
 * Catalog-specific bits:
 *   - Gold corner badge    = owned_count (always visible, independent
 *                             of the A/B toggle — Catalog's overlay bar
 *                             is also always anchored at the bottom)
 *   - Red badge top-right  = wanted_by_others
 *   - Ownership shine      = green / blue / red glow around the strip
 *   - Add-to-deck menu     = pops out when used inside a deck builder
 */
const props = defineProps({
  card:   { type: Object, required: true },
  deckId: { type: Number, default: null },
})
const emit = defineEmits(['click'])

const catalog = useCatalogStore()

const effectiveScryfallId = computed(
  () => catalog.activePrintings[props.card.oracle_id] || props.card.scryfall_id,
)

// Mirror CardTile: swap to the selected printing's image when the user has
// picked a non-default printing. Wipes on the next search.
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

const shineClass = computed(() => {
  const o = props.card?.owned_count ?? 0
  const a = props.card?.available_count ?? 0
  if (o > 0 && a > 0) return 'shine-green'
  if (o > 0 && a === 0) return 'shine-blue'
  return 'shine-red'
})

function onClick() {
  catalog.setActiveCard(props.card.oracle_id)
  emit('click')
}

function onDragStart(e) {
  e.dataTransfer.setData('application/json', JSON.stringify({
    oracle_id: props.card.oracle_id,
    scryfall_id: effectiveScryfallId.value,
    source: 'catalog',
  }))
  // See CardTile.onDragStart — sentinel MIME so the deck grid sets
  // dropEffect='copy' and the browser actually fires the drop.
  e.dataTransfer.setData('application/x-vk-catalog', '1')
  e.dataTransfer.effectAllowed = 'copy'
}
</script>

<template>
  <BaseCardStrip
    :card="displayCard"
    :show-qty-in-bar="false"
    :corner-count="card.owned_count || 0"
    :show-corner-badge="(card.owned_count || 0) > 0"
    :corner-badge-always-visible="true"
    :draggable="true"
    :loading-lazy="true"
    :class="shineClass"
    @click="onClick"
    @dragstart="onDragStart"
  >
    <template #badges>
      <span
        v-if="card.wanted_by_others > 0"
        class="badge badge-wanted"
      >{{ card.wanted_by_others }}</span>
    </template>
  </BaseCardStrip>
</template>

<style scoped>
/* Plain dark border — matches real MTG card framing. Focus/hover
   feedback comes from the ownership glow + transform, not from a
   colour-changing outline. */
.strip {
  border: 1px solid #0a0a0a;
}

/* Ownership shine — backlit halo in the ownership colour. Same
   treatment as CardTile, tuned a hair tighter for the smaller strip.
   The base .strip rule transitions box-shadow for the dark elevation
   shadow on hover; that transition re-rasterises the blurred shine
   every frame for 60+ catalog strips at once. Override it here to kill
   the box-shadow entry from the transition list — the shine still
   swaps on hover, just snap-changes instead of animating. */
.strip.shine-green,
.strip.shine-blue,
.strip.shine-red {
  transition: height 160ms ease-out, margin-bottom 160ms ease-out,
              outline-color 120ms ease, transform 160ms ease-out;
}
.strip.shine-green {
  box-shadow:
    0 0 5px 1px rgba(124, 185, 142, 0.85),
    0 0 16px 4px rgba(124, 185, 142, 0.45);
}
.strip.shine-blue {
  box-shadow:
    0 0 5px 1px rgba(108, 154, 210, 0.85),
    0 0 16px 4px rgba(108, 154, 210, 0.45);
}
.strip.shine-red {
  box-shadow:
    0 0 4px 1px rgba(208, 106, 106, 0.55),
    0 0 12px 3px rgba(208, 106, 106, 0.25);
}
.strip:hover.shine-green {
  box-shadow:
    0 0 7px 2px rgba(124, 185, 142, 0.95),
    0 0 22px 8px rgba(124, 185, 142, 0.55);
}
.strip:hover.shine-blue {
  box-shadow:
    0 0 7px 2px rgba(108, 154, 210, 0.95),
    0 0 22px 8px rgba(108, 154, 210, 0.55);
}
.strip:hover.shine-red {
  box-shadow:
    0 0 6px 2px rgba(208, 106, 106, 0.7),
    0 0 18px 6px rgba(208, 106, 106, 0.35);
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
</style>
