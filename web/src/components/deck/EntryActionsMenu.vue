<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useDeckEntryActions } from '../../composables/useDeckEntryActions'
import { useDeckStore } from '../../stores/deck'

/**
 * Right-click context menu for a deck entry. Anchors at the click position
 * (page coords); teleports to body so it overlays the entire deck-builder
 * shell. Closes on outside click, Escape, scroll, or once any action runs.
 *
 * Drives both the standard promote/demote/swap actions (delegated to
 * useDeckEntryActions) and a couple of always-available items (View,
 * Remove from deck) so users get one consistent menu everywhere.
 */
const props = defineProps({
  entry: { type: Object, default: null },
  position: { type: Object, default: null }, // { x, y } page coords or null=closed
})
const emit = defineEmits(['close'])

const deck = useDeckStore()
const entryRef = computed(() => props.entry)
const { actions } = useDeckEntryActions(entryRef)

const open = computed(() => !!props.position && !!props.entry)

// Final on-screen position. Clamped to the viewport so the menu never
// overflows past the right/bottom edge.
const root = ref(null)
const position = ref({ x: 0, y: 0 })

watch(open, async (isOpen) => {
  if (!isOpen) return
  position.value = { x: props.position.x, y: props.position.y }
  await nextTick()
  if (!root.value) return
  const rect = root.value.getBoundingClientRect()
  const vw = window.innerWidth
  const vh = window.innerHeight
  let x = props.position.x
  let y = props.position.y
  if (x + rect.width > vw - 8) x = Math.max(8, vw - rect.width - 8)
  if (y + rect.height > vh - 8) y = Math.max(8, vh - rect.height - 8)
  position.value = { x, y }
})

function close() {
  emit('close')
}

function onDocClick(e) {
  if (!open.value) return
  if (root.value && !root.value.contains(e.target)) close()
}
function onKey(e) {
  if (e.key === 'Escape') close()
}
function onScroll() {
  // Re-anchoring during scroll is tricky and rarely useful; just close.
  if (open.value) close()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('mousedown', onDocClick, true)
    document.addEventListener('keydown', onKey)
    window.addEventListener('scroll', onScroll, true)
  } else {
    document.removeEventListener('mousedown', onDocClick, true)
    document.removeEventListener('keydown', onKey)
    window.removeEventListener('scroll', onScroll, true)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', onScroll, true)
})

async function run(action) {
  close()
  try {
    await action.run()
  } catch {
    // Toast already surfaced from the store action.
  }
}

function viewDetails() {
  if (props.entry) deck.activeEntryId = props.entry.id
  close()
}

async function removeFromDeck() {
  if (!props.entry || !deck.deck?.id) return
  close()
  try {
    await deck.removeEntry(deck.deck.id, props.entry.id)
  } catch { /* toasted */ }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="root"
      class="entry-actions-menu"
      role="menu"
      :style="{ left: position.x + 'px', top: position.y + 'px' }"
      @contextmenu.prevent
    >
      <button type="button" class="menu-item" role="menuitem" @click="viewDetails">
        <span class="menu-label">View details</span>
      </button>
      <div v-if="actions.length" class="menu-sep" />
      <button
        v-for="a in actions"
        :key="a.id"
        type="button"
        class="menu-item"
        :class="{ 'menu-item--primary': a.kind === 'primary' }"
        role="menuitem"
        @click="run(a)"
      >
        <span class="menu-label">{{ a.label }}</span>
        <span v-if="a.hint" class="menu-hint">{{ a.hint }}</span>
      </button>
      <div class="menu-sep" />
      <button
        type="button"
        class="menu-item menu-item--danger"
        role="menuitem"
        @click="removeFromDeck"
      >
        <span class="menu-label">Remove from deck</span>
      </button>
    </div>
  </Teleport>
</template>

<style scoped>
.entry-actions-menu {
  position: fixed;
  z-index: 9999;
  min-width: 220px;
  max-width: 320px;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.55);
  padding: 4px;
  display: flex;
  flex-direction: column;
}
.menu-item {
  background: transparent;
  border: 0;
  color: inherit;
  text-align: left;
  padding: 0.45rem 0.6rem;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.menu-item:hover,
.menu-item:focus-visible {
  background: var(--bg-2-sunken, #1c1a16);
  outline: none;
}
.menu-item--primary .menu-label {
  color: var(--amber, #c9a552);
}
.menu-item--danger .menu-label {
  color: #d97a6c;
}
.menu-label { font-size: 0.85rem; }
.menu-hint  { font-size: 0.72rem; color: var(--ink-70, #a8a396); }
.menu-sep {
  height: 1px;
  background: var(--hairline, #33312c);
  margin: 4px 0;
}
</style>
