<script setup>
import { computed, ref } from 'vue'
import { useSettingsStore } from '../../stores/settings'
import { useDeckStore } from '../../stores/deck'
import BaseCardStrip from '../strip/BaseCardStrip.vue'
import EntryActionsMenu from './EntryActionsMenu.vue'
import WantedMatchAvatarStack from './matcher/WantedMatchAvatarStack.vue'

/**
 * Deck-view strip. Thin wrapper over BaseCardStrip that adds the
 * drag-to-remove payload, the top-right quantity pill (Mode A only —
 * Mode B uses the base's gold corner badge), the Game Changer flag,
 * and the illegal-glow border.
 *
 * Also renders the WantedMatchAvatarStack for wanted rows: cheap purely
 * presentational overlay — no reactive computation per row beyond
 * reading `matchFriends` from the parent's pre-computed map.
 *
 * Same A/B visual split as the collection strip. Reads
 * settings.displayMode so the Location sidebar toggle applies here too.
 */
const props = defineProps({
  entry: { type: Object, required: true },
  illegal: { type: Boolean, default: false },
  showGameChanger: { type: Boolean, default: false },
  /** Friends array from the wanted-matches response for this card, or null. */
  matchFriends: { type: Array, default: null },
  /** True while the deck-level wanted-matches fetch is in flight. */
  matchLoading: { type: Boolean, default: false },
})
const emit = defineEmits(['click', 'peek-show', 'peek-hide', 'match-open'])

const settings = useSettingsStore()
const deckStore = useDeckStore()

const card = computed(() => props.entry?.scryfall_card || {})
const qty = computed(() => props.entry?.quantity || 0)
const isModeA = computed(() => settings.displayMode === 'A')

// Partial-exclude split rows (locked decision 3.3): the merged view
// surfaces these flags so the strip can render an "owned X / wanted Y"
// badge instead of a single quantity pill.
const isSplit = computed(() => !!props.entry?._split)
const ownedQty = computed(() => props.entry?.owned_quantity ?? null)
const wantedQty = computed(() => props.entry?.wanted_quantity ?? null)

// Show the avatar stack on rows that have a wanted component (either a
// pure wanted entry or the wanted side of a split row). The stack is
// hidden when matchFriends is null (card isn't in the wanted-matches
// response — e.g. fully assembled rows).
const isWanted = computed(() =>
  props.entry?.physical_copy_id == null || !!props.entry?._split,
)
const showAvatarStack = computed(() => isWanted.value)

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

function onPeekShow({ rect }) {
  emit('peek-show', { entry: props.entry, rect })
}
</script>

<template>
  <BaseCardStrip
    :card="card"
    :quantity="qty"
    :show-qty-in-bar="false"
    :mode-b="settings.displayMode === 'B'"
    :hover-mode="settings.hoverMode"
    :draggable="true"
    :loading-lazy="true"
    :class="{ 'illegal-glow': illegal }"
    @click="emit('click', entry)"
    @contextmenu="onContextMenu"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @peek-show="onPeekShow"
    @peek-hide="emit('peek-hide')"
  >
    <template #badges>
      <!-- Mode A keeps the square pill in the top-right. Mode B replaces
           it with the base's animated corner badge, so hide this one to
           avoid stacking two quantity markers. -->
      <span v-if="qty > 1 && isModeA && !isSplit" class="qty-badge">{{ qty }}</span>
      <!-- Split rows (partial-exclude assemble): show owned vs. wanted
           so the user knows the slot is half-fulfilled. Renders in both
           display modes since it's strictly informational, not just a
           quantity stand-in. -->
      <span
        v-if="isSplit"
        class="split-badge"
        :title="`Owned ${ownedQty}, wanted ${wantedQty}`"
      >{{ ownedQty }}<span class="sep">/</span>{{ wantedQty }}</span>
      <!-- Friend avatar stack — only on wanted rows. Sits bottom-left
           so it doesn't collide with the qty badge (top-right). Cheap:
           no async or watchers here — matchFriends is pre-computed by
           the parent DeckGrid using the deck-level matchFor() map. -->
      <WantedMatchAvatarStack
        v-if="showAvatarStack"
        class="strip-avatar-stack"
        :friends="matchFriends ?? []"
        :loading="matchLoading && matchFriends === null"
        @open="emit('match-open', entry)"
      />
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

/* Split-row "owned/wanted" badge for partial-exclude assemble (locked
   decision 3.3). Same anchor as the regular qty pill but coloured
   amber so users notice the half-fulfilled state without reading the
   tooltip. */
.split-badge {
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
  gap: 1px;
  font-family: var(--font-mono), monospace;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.02em;
  background: rgba(201, 162, 39, 0.92);
  color: #1a120c;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.45);
  pointer-events: none;
  z-index: 3;
}
.split-badge .sep {
  opacity: 0.6;
  margin: 0 1px;
}

/* .illegal-glow itself is a global rule in style.css (shared pulse
   with DeckCardTile / CommanderZone); nothing strip-specific to add. */

/* Avatar stack — anchored bottom-left inside the strip clip so it
   doesn't collide with the qty badge (top-right). pointer-events: auto
   overrides the parent slot's none so clicks reach the button inside.
   z-index 4 keeps the stack above the overlay bar (z:3). */
:deep(.strip-avatar-stack) {
  position: absolute;
  bottom: 4px;
  left: 4px;
  z-index: 4;
  pointer-events: auto;
}
</style>
