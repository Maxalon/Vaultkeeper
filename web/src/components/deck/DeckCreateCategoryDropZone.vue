<script setup>
import { ref } from 'vue'
import { useDeckStore } from '../../stores/deck'

/**
 * Floating "drop to create category" target. Mirrors DeckRemoveDropZone
 * but pinned to the top-right and styled with the amber accent. When a
 * deck entry is dropped here we prompt for a new category name and patch
 * the entry's category field.
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
  deck.setDragEntry(null)
  const name = window.prompt('New category name')
  const trimmed = (name || '').trim()
  if (!trimmed) return
  if (deck.deck?.id) {
    deck.updateEntry(deck.deck.id, payload.deckEntryId, { category: trimmed })
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="deck.dragEntryId !== null"
      class="create-zone"
      :class="{ hover }"
      @dragenter="hover = true"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <svg class="icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z" />
        <path d="M12 11v6" />
        <path d="M9 14h6" />
      </svg>
      <span class="label">Drop to create category</span>
    </div>
  </Teleport>
</template>

<style scoped>
.create-zone {
  position: fixed;
  right: 28px;
  top: 28px;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 180px;
  padding: 18px 20px;
  border-radius: 12px;
  background: rgba(26, 20, 12, 0.92);
  border: 2px dashed var(--cat-accent, rgba(224, 176, 96, 0.55));
  color: var(--amber, #e0b060);
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.6);
  pointer-events: auto;
  user-select: none;
  animation: cat-slide-in 140ms ease-out;
}
.create-zone.hover {
  background: color-mix(in oklab, var(--amber) 22%, rgba(26, 20, 12, 0.92));
  border-color: var(--amber-hi, #f1c87a);
  color: #fff;
  transform: scale(1.04);
  transition: transform 100ms ease, background 100ms ease;
}
.icon {
  display: block;
  stroke: currentColor;
}
.label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
  text-align: center;
}

@keyframes cat-slide-in {
  from { opacity: 0; transform: translateY(-12px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>
