<script setup>
import { computed } from 'vue'
import { useDeckEntryActions } from '../../composables/useDeckEntryActions'
import { useDeckStore } from '../../stores/deck'
import PopoverMenu from '../PopoverMenu.vue'

// Right-click context menu for a deck entry. Anchors at the click position
// (page coords) via PopoverMenu, which handles teleport / viewport clamping
// / outside-click / Escape. Drives the promote/demote/swap actions
// (delegated to useDeckEntryActions) plus a couple of always-available
// items (View, Remove from deck) so users get one consistent menu.
const props = defineProps({
  entry: { type: Object, default: null },
  position: { type: Object, default: null }, // { x, y } page coords or null=closed
})
const emit = defineEmits(['close'])

const deck = useDeckStore()
const entryRef = computed(() => props.entry)
const { actions } = useDeckEntryActions(entryRef)

const open = computed(() => !!props.position && !!props.entry)

function close() {
  emit('close')
}

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
  <PopoverMenu
    :open="open"
    :anchor-position="position"
    menu-class="entry-actions-menu"
    @close="close"
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
  </PopoverMenu>
</template>

<style scoped>
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

<style>
.entry-actions-menu {
  min-width: 220px;
  max-width: 320px;
}
</style>
