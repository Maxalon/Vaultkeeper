<script setup>
import { computed, ref } from 'vue'
import DfcPopover from '../DfcPopover.vue'
import { useDeckStore } from '../../stores/deck'

const deckStore = useDeckStore()

/**
 * Grid tile for a deck entry. Visually mirrors CardTile from the catalog
 * view — DFC back-face popover on hover, same sizing via --card-width —
 * but drops the ownership-shine / add-to-deck concerns. Adds:
 *   - quantity badge (upper-right)
 *   - game-changer badge (lower-left, commander only)
 *   - illegal glow (red halo)
 *   - drag-to-remove: if the drag ends without any zone accepting the
 *     drop (dropEffect === 'none'), emit `remove` so the parent grid
 *     can call deck.removeEntry().
 */
const props = defineProps({
  entry: { type: Object, required: true },
  illegal: { type: Boolean, default: false },
  showGameChanger: { type: Boolean, default: false },
})
const emit = defineEmits(['click'])

const rootRef = ref(null)
const hovered = ref(false)
let hoverTimer = null

const card = computed(() => props.entry?.scryfall_card || {})
const qty = computed(() => props.entry?.quantity || 0)

const imageSrc = computed(
  () => card.value.image_normal || card.value.image_small,
)

function onClick() {
  emit('click', props.entry)
}

function onHoverEnter() {
  if (!card.value.is_dfc) return
  hoverTimer = setTimeout(() => { hovered.value = true }, 300)
}
function onHoverLeave() {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
  hovered.value = false
}

function onDragStart(e) {
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/json', JSON.stringify({
    source: 'deck',
    deckEntryId: props.entry.id,
    scryfall_id: props.entry.scryfall_id,
    zone: props.entry.zone,
  }))
  deckStore.setDragEntry(props.entry.id)
}
function onDragEnd() {
  deckStore.setDragEntry(null)
}
</script>

<template>
  <div
    ref="rootRef"
    class="deck-card-tile"
    :class="{ 'illegal-glow': illegal }"
    :draggable="true"
    @click="onClick"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @mouseenter="onHoverEnter"
    @mouseleave="onHoverLeave"
  >
    <img
      v-if="imageSrc"
      :src="imageSrc"
      :alt="card.name"
      loading="lazy"
      decoding="async"
    />
    <div v-else class="name-fallback">{{ card.name }}</div>

    <span v-if="qty > 1" class="qty-badge">{{ qty }}</span>
    <span v-if="showGameChanger && card.commander_game_changer" class="gc-badge">GC</span>

    <DfcPopover
      v-if="card.is_dfc && hovered && card.image_normal_back"
      :back-image="card.image_normal_back"
      :anchor="rootRef"
    />
  </div>
</template>

<style scoped>
.deck-card-tile {
  position: relative;
  width: var(--card-width);
  aspect-ratio: 63 / 88;
  /* Percentage-based radius hugs the Scryfall card's baked-in rounded
     corners so the dark tile background doesn't show through as square
     "white" corner specks. */
  border-radius: 4.5% / 3.2%;
  overflow: hidden;
  cursor: pointer;
  border: 1px solid #0a0a0a;
  background: #1a1a22;
  content-visibility: auto;
  contain-intrinsic-size: auto calc(var(--card-width) * 88 / 63);
  transition: transform 100ms ease, box-shadow 150ms ease;
}
.deck-card-tile:hover {
  transform: translateY(-2px);
  z-index: 2;
}
.deck-card-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: inherit;
}
.name-fallback {
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
.qty-badge, .gc-badge {
  position: absolute;
  min-width: 24px;
  height: 22px;
  padding: 0 7px;
  border-radius: 11px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  font-weight: 700;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
  pointer-events: none;
}
.qty-badge {
  top: 6px;
  right: 6px;
  background: rgba(0, 0, 0, 0.78);
  color: #fff;
}
.gc-badge {
  bottom: 6px;
  left: 6px;
  background: var(--vk-gold, #c9a552);
  color: #1a1408;
}
</style>
