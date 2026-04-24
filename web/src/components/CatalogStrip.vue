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
const emit = defineEmits(['click', 'add-to-deck'])

const catalog = useCatalogStore()

const addMenuOpen = ref(false)

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

function onDragStart(e) {
  e.dataTransfer.setData('application/json', JSON.stringify({
    oracle_id: props.card.oracle_id,
    scryfall_id: effectiveScryfallId.value,
    source: 'catalog',
  }))
  e.dataTransfer.effectAllowed = 'copy'
}

function emitAdd(zone) {
  emit('add-to-deck', { scryfall_id: effectiveScryfallId.value, zone })
  addMenuOpen.value = false
}
</script>

<template>
  <BaseCardStrip
    :card="card"
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

    <template #menu>
      <div v-if="deckId && addMenuOpen" class="add-menu" @click.stop>
        <button type="button" @click="emitAdd('main')">+ Main</button>
        <button type="button" @click="emitAdd('side')">+ Side</button>
        <button type="button" @click="emitAdd('maybe')">+ Maybe</button>
      </div>
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
