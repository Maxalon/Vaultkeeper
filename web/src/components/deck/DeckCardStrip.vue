<script setup>
import { computed, ref } from 'vue'
import { useSettingsStore } from '../../stores/settings'
import { useDeckStore } from '../../stores/deck'
import BaseCardStrip from '../strip/BaseCardStrip.vue'
import EntryActionsMenu from './EntryActionsMenu.vue'

/**
 * Deck-view strip. Thin wrapper over BaseCardStrip that adds the
 * drag-to-remove payload, the top-right quantity pill (Mode A only —
 * Mode B uses the base's gold corner badge), the Game Changer flag,
 * and the illegal-glow border.
 *
 * Same A/B visual split as the collection strip. Reads
 * settings.displayMode so the Location sidebar toggle applies here too.
 */
const props = defineProps({
  entry: { type: Object, required: true },
  illegal: { type: Boolean, default: false },
  showGameChanger: { type: Boolean, default: false },
})
const emit = defineEmits(['click'])

const settings = useSettingsStore()
const deckStore = useDeckStore()

const card = computed(() => props.entry?.scryfall_card || {})
const qty = computed(() => props.entry?.quantity || 0)
const isModeA = computed(() => settings.displayMode === 'A')

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

const menuPos = ref(null)
function onContextMenu(e) {
  e.preventDefault()
  menuPos.value = { x: e.clientX, y: e.clientY }
}
function closeMenu() { menuPos.value = null }
</script>

<template>
  <BaseCardStrip
    :card="card"
    :quantity="qty"
    :show-qty-in-bar="false"
    :show-skeleton="false"
    :mode-b="settings.displayMode === 'B'"
    :draggable="true"
    :loading-lazy="true"
    :class="{ 'illegal-glow': illegal }"
    @click="emit('click', entry)"
    @contextmenu="onContextMenu"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
  >
    <template #badges>
      <!-- Mode A keeps the square pill in the top-right. Mode B replaces
           it with the base's animated corner badge, so hide this one to
           avoid stacking two quantity markers. -->
      <span v-if="qty > 1 && isModeA" class="qty-badge">{{ qty }}</span>
    </template>
    <template #overlay-extras>
      <span
        v-if="showGameChanger && card.commander_game_changer"
        class="gc-badge"
      >GC</span>
    </template>
  </BaseCardStrip>
  <EntryActionsMenu :entry="entry" :position="menuPos" @close="closeMenu" />
</template>

<style scoped>
/* Dark dividing border — matches real MTG card framing for the
   deck-grid layout. Base leaves border unset so this is additive. */
.strip {
  border: 1px solid #0a0a0a;
}

/* Floating quantity pill, top-right of the strip-clip. */
.qty-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  min-width: 22px;
  height: 20px;
  padding: 0 6px;
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
  background: rgba(0, 0, 0, 0.82);
  color: #fff;
}

/* GC pill rides inside the overlay bar (left of mana cost) — visual
   styling comes from the global .gc-badge rule in style.css. */

/* .illegal-glow itself is a global rule in style.css (shared pulse
   with DeckCardTile / CommanderZone); nothing strip-specific to add. */
</style>
