<script setup>
import { ref } from 'vue'
import { useDeckStore } from '../../stores/deck'

/**
 * Floating "drop to remove" target. Rendered only while a deck entry is
 * being dragged (deckStore.dragEntryId !== null). Pinned to the bottom-
 * right of the viewport via a Teleport so it's reachable no matter what
 * the user is scrolled to. Drops parse the deck-sourced payload and
 * delete the entry.
 */
const deck = useDeckStore()
const hover = ref(false)

function acceptsDrop(e) {
  return (e.dataTransfer?.types || []).includes('application/json')
}
function onDragOver(e) {
  if (!acceptsDrop(e)) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  hover.value = true
}
function onDragLeave() {
  hover.value = false
}
function onDrop(e) {
  hover.value = false
  const raw = e.dataTransfer?.getData('application/json')
  if (!raw) return
  let payload
  try { payload = JSON.parse(raw) } catch { return }
  if (payload.source !== 'deck' || !payload.deckEntryId) return
  e.preventDefault()
  e.stopPropagation()
  if (deck.deck?.id) deck.removeEntry(deck.deck.id, payload.deckEntryId)
  deck.setDragEntry(null)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="deck.dragEntryId !== null"
      class="remove-zone"
      :class="{ hover }"
      @dragenter="hover = true"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <svg class="bin" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        <path d="M10 11v6" />
        <path d="M14 11v6" />
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
      </svg>
      <span class="label">Drop to remove</span>
    </div>
  </Teleport>
</template>

<style scoped>
.remove-zone {
  position: fixed;
  right: 28px;
  bottom: 28px;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 180px;
  padding: 18px 20px;
  border-radius: 12px;
  background: rgba(26, 20, 20, 0.92);
  border: 2px dashed rgba(208, 106, 106, 0.7);
  color: #e8a6a6;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.6);
  pointer-events: auto;
  user-select: none;
  animation: bin-slide-in 140ms ease-out;
}
.remove-zone.hover {
  background: rgba(120, 40, 40, 0.75);
  border-color: #e57272;
  color: #fff;
  transform: scale(1.04);
  transition: transform 100ms ease, background 100ms ease;
}
.bin {
  display: block;
  stroke: currentColor;
}
.label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
}

@keyframes bin-slide-in {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>
