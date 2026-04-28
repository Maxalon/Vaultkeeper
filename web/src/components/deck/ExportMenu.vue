<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue'

const emit = defineEmits(['select'])

const open = ref(false)
const btnRef = ref(null)
const menuRef = ref(null)
const menuStyle = ref({ top: '0px', left: '0px' })

const OPTIONS = [
  { format: 'text', label: 'Plain text (.txt)', hint: 'Vaultkeeper format · re-importable' },
  { format: 'moxfield', label: 'Moxfield / Arena (.txt)', hint: 'Flat Deck / Sideboard' },
]

function onDocClick(e) {
  const target = e.target
  if (btnRef.value && btnRef.value.contains(target)) return
  if (menuRef.value && menuRef.value.contains(target)) return
  close()
}
function onKey(e) {
  if (e.key === 'Escape') close()
}
function updatePosition() {
  if (!btnRef.value) return
  const rect = btnRef.value.getBoundingClientRect()
  menuStyle.value = {
    top: `${rect.bottom + 4}px`,
    left: `${rect.left}px`,
  }
}
async function toggle() {
  if (open.value) {
    close()
    return
  }
  open.value = true
  await nextTick()
  updatePosition()
  document.addEventListener('mousedown', onDocClick, true)
  document.addEventListener('keydown', onKey)
  window.addEventListener('resize', updatePosition)
  window.addEventListener('scroll', updatePosition, true)
}
function close() {
  open.value = false
  document.removeEventListener('mousedown', onDocClick, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', updatePosition)
  window.removeEventListener('scroll', updatePosition, true)
}
function choose(format, action) {
  close()
  emit('select', { action, format })
}

onBeforeUnmount(() => {
  close()
})
</script>

<template>
  <div class="export-menu">
    <button
      ref="btnRef"
      type="button"
      class="action-btn"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="toggle"
    >
      Export <span class="caret">▾</span>
    </button>
    <Teleport to="body">
      <div v-if="open" ref="menuRef" class="export-menu-popup" role="menu" :style="menuStyle">
        <div
          v-for="opt in OPTIONS"
          :key="opt.format"
          class="menu-row"
        >
          <button
            type="button"
            class="menu-item"
            role="menuitem"
            @click="choose(opt.format, 'download')"
          >
            <span class="menu-label">{{ opt.label }}</span>
            <span class="menu-hint">{{ opt.hint }}</span>
          </button>
          <button
            type="button"
            class="copy-btn"
            role="menuitem"
            :aria-label="`Copy ${opt.label} to clipboard`"
            title="Copy to clipboard"
            @click.stop="choose(opt.format, 'copy')"
          >
            <svg
              viewBox="0 0 24 24"
              width="14"
              height="14"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <rect x="9" y="9" width="11" height="11" rx="2" ry="2" />
              <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
            </svg>
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.export-menu {
  display: inline-block;
}
.action-btn {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 0.25rem 0.6rem;
  font-size: 0.8rem;
  cursor: pointer;
  border-radius: 4px;
}
.action-btn:hover { background: var(--bg-2, #26241f); }
.caret {
  font-size: 0.7em;
  opacity: 0.75;
  margin-left: 0.15rem;
}
</style>

<style>
.export-menu-popup {
  position: fixed;
  min-width: 240px;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
  padding: 4px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
}
.export-menu-popup .menu-row {
  display: flex;
  align-items: stretch;
  gap: 2px;
}
.export-menu-popup .menu-item {
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
  flex: 1 1 auto;
}
.export-menu-popup .menu-item:hover,
.export-menu-popup .menu-item:focus-visible {
  background: var(--bg-2-sunken, #1c1a16);
  outline: none;
}
.export-menu-popup .menu-label {
  font-size: 0.85rem;
}
.export-menu-popup .menu-hint {
  font-size: 0.72rem;
  color: var(--ink-70, #a8a396);
}
.export-menu-popup .copy-btn {
  background: transparent;
  border: 0;
  color: var(--ink-70, #a8a396);
  padding: 0 0.55rem;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
}
.export-menu-popup .copy-btn:hover,
.export-menu-popup .copy-btn:focus-visible {
  background: var(--bg-2-sunken, #1c1a16);
  color: inherit;
  outline: none;
}
</style>
