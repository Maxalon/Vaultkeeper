<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

// Teleported, fixed-position dropdown shell. Sidesteps ancestor
// `overflow: hidden` clipping (the deck header is the recurring culprit)
// by rendering to <body> outside the scroll/stacking context.
//
// Two anchoring modes:
//   - anchorEl: HTMLElement — opens below the element (placement controls
//     left/right alignment). Repositions on scroll/resize.
//   - anchorPosition: { x, y } page coords — used for click-position menus
//     (e.g. context menus). Closes on scroll since the anchor is static.

const props = defineProps({
  open: { type: Boolean, default: false },
  anchorEl: { type: [Object, null], default: null },
  anchorPosition: { type: [Object, null], default: null },
  placement: {
    type: String,
    default: 'bottom-start',
    validator: (v) => ['bottom-start', 'bottom-end'].includes(v),
  },
  offset: { type: Number, default: 4 },
  menuClass: { type: String, default: '' },
})
const emit = defineEmits(['close'])

const root = ref(null)
const style = ref({ top: '-9999px', left: '-9999px' })

function getAnchorEl() {
  const a = props.anchorEl
  if (!a) return null
  return a.$el ?? a
}

function reposition() {
  if (!root.value) return
  const menu = root.value.getBoundingClientRect()
  const vw = window.innerWidth
  const vh = window.innerHeight
  let top
  let left

  if (props.anchorPosition) {
    top = props.anchorPosition.y
    left = props.anchorPosition.x
  } else {
    const el = getAnchorEl()
    if (!el) return
    const r = el.getBoundingClientRect()
    top = r.bottom + props.offset
    left = props.placement === 'bottom-end' ? r.right - menu.width : r.left
  }

  if (left + menu.width > vw - 8) left = Math.max(8, vw - menu.width - 8)
  if (left < 8) left = 8
  if (top + menu.height > vh - 8) top = Math.max(8, vh - menu.height - 8)
  if (top < 8) top = 8

  style.value = { top: `${top}px`, left: `${left}px` }
}

function onDocClick(e) {
  if (!props.open) return
  if (root.value && root.value.contains(e.target)) return
  const anchor = getAnchorEl()
  if (anchor && anchor.contains(e.target)) return
  emit('close')
}
function onKey(e) {
  if (e.key === 'Escape') emit('close')
}
function onScroll() {
  if (props.anchorEl) reposition()
  else emit('close')
}

function bind() {
  document.addEventListener('mousedown', onDocClick, true)
  document.addEventListener('keydown', onKey)
  window.addEventListener('resize', reposition)
  window.addEventListener('scroll', onScroll, true)
}
function unbind() {
  document.removeEventListener('mousedown', onDocClick, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', reposition)
  window.removeEventListener('scroll', onScroll, true)
}

watch(
  () => props.open,
  async (isOpen) => {
    if (isOpen) {
      await nextTick()
      reposition()
      bind()
    } else {
      unbind()
    }
  },
)

watch(
  () => props.anchorPosition,
  async () => {
    if (props.open) {
      await nextTick()
      reposition()
    }
  },
)

onBeforeUnmount(unbind)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="root"
      class="popover-menu"
      :class="menuClass"
      role="menu"
      :style="style"
    >
      <slot />
    </div>
  </Teleport>
</template>

<style scoped>
.popover-menu {
  position: fixed;
  z-index: 1000;
  min-width: 160px;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
  padding: 4px;
  display: flex;
  flex-direction: column;
}
</style>
