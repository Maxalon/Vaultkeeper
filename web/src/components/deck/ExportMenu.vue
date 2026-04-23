<script setup>
import { onBeforeUnmount, ref } from 'vue'

const emit = defineEmits(['select'])

const open = ref(false)
const root = ref(null)

const OPTIONS = [
  { format: 'text', label: 'Plain text (.txt)', hint: 'Vaultkeeper format · re-importable' },
  { format: 'moxfield', label: 'Moxfield / Arena (.txt)', hint: 'Flat Deck / Sideboard' },
  { format: 'json', label: 'JSON (.json)', hint: 'Full deck data' },
]

function onDocClick(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
function onKey(e) {
  if (e.key === 'Escape') open.value = false
}
function toggle() {
  open.value = !open.value
  if (open.value) {
    document.addEventListener('mousedown', onDocClick)
    document.addEventListener('keydown', onKey)
  } else {
    document.removeEventListener('mousedown', onDocClick)
    document.removeEventListener('keydown', onKey)
  }
}
function choose(format) {
  open.value = false
  document.removeEventListener('mousedown', onDocClick)
  document.removeEventListener('keydown', onKey)
  emit('select', format)
}

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
  document.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div ref="root" class="export-menu">
    <button
      type="button"
      class="action-btn"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="toggle"
    >
      Export <span class="caret">▾</span>
    </button>
    <div v-if="open" class="menu" role="menu">
      <button
        v-for="opt in OPTIONS"
        :key="opt.format"
        type="button"
        class="menu-item"
        role="menuitem"
        @click="choose(opt.format)"
      >
        <span class="menu-label">{{ opt.label }}</span>
        <span class="menu-hint">{{ opt.hint }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.export-menu {
  position: relative;
  display: inline-block;
}
.action-btn {
  background: transparent;
  border: 1px solid var(--vk-border, #33312c);
  color: inherit;
  padding: 0.25rem 0.6rem;
  font-size: 0.8rem;
  cursor: pointer;
  border-radius: 4px;
}
.action-btn:hover { background: var(--vk-surface-raised, #26241f); }
.caret {
  font-size: 0.7em;
  opacity: 0.75;
  margin-left: 0.15rem;
}
.menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  min-width: 220px;
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  border-radius: 6px;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
  padding: 4px;
  z-index: 20;
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
  background: var(--vk-surface-sunken, #1c1a16);
  outline: none;
}
.menu-label {
  font-size: 0.85rem;
}
.menu-hint {
  font-size: 0.72rem;
  color: var(--vk-fg-dim, #a8a396);
}
</style>
