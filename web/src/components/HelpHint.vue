<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'

const props = defineProps({
  text: { type: String, required: true },
  position: {
    type: String,
    default: 'top',
    validator: (v) => ['top', 'bottom', 'right'].includes(v),
  },
  width: { type: Number, default: 240 },
})

// Position the bubble via JS + Teleport to <body> so it can escape any
// `overflow: hidden` ancestor (e.g. the deck sidebar's clipping
// container). Pure CSS hover with `position: absolute` would be
// clipped to the nearest scrollable / hidden ancestor, which is why
// the Settings .info pattern looked clipped here.
const triggerRef = ref(null)
const visible = ref(false)
const coords = ref({ top: 0, left: 0 })

function recompute() {
  const el = triggerRef.value
  if (!el) return
  const r = el.getBoundingClientRect()
  const w = props.width
  // Approximate height — laid out via the bubble's intrinsic size,
  // refined right after mount. 12px gap matches the SettingsView
  // pattern's padding feel.
  const gap = 8
  let top, left
  if (props.position === 'bottom') {
    top  = r.bottom + gap
    left = r.left + r.width / 2 - w / 2
  } else if (props.position === 'right') {
    top  = r.top + r.height / 2
    left = r.right + gap
  } else {
    top  = r.top - gap
    left = r.left + r.width / 2 - w / 2
  }
  // Clamp horizontally to the viewport so long tooltips don't bleed
  // past the right edge or under the left rail.
  const minLeft = 8
  const maxLeft = window.innerWidth - w - 8
  if (left < minLeft) left = minLeft
  if (left > maxLeft) left = Math.max(minLeft, maxLeft)
  coords.value = { top, left }
}

function show() {
  recompute()
  visible.value = true
}
function hide() { visible.value = false }

// Recompute on scroll / resize while visible — covers the sidebar
// scroll position changing while the tooltip is open.
function onWindowChange() {
  if (visible.value) recompute()
}
if (typeof window !== 'undefined') {
  window.addEventListener('scroll', onWindowChange, true)
  window.addEventListener('resize', onWindowChange)
  onBeforeUnmount(() => {
    window.removeEventListener('scroll', onWindowChange, true)
    window.removeEventListener('resize', onWindowChange)
  })
}

const bubbleStyle = computed(() => {
  const { top, left } = coords.value
  const transform = props.position === 'top'
    ? 'translateY(-100%)'
    : props.position === 'right'
      ? 'translateY(-50%)'
      : 'none'
  return {
    top: `${top}px`,
    left: `${left}px`,
    width: `${props.width}px`,
    transform,
  }
})
</script>

<template>
  <span
    ref="triggerRef"
    class="help-hint"
    tabindex="0"
    role="tooltip"
    aria-label="Help"
    @mouseenter="show"
    @mouseleave="hide"
    @focus="show"
    @blur="hide"
  >?</span>
  <Teleport to="body">
    <span v-if="visible" class="help-hint-bubble" :style="bubbleStyle">
      {{ text }}
    </span>
  </Teleport>
</template>

<style scoped>
.help-hint {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 1px solid var(--ink-30, rgba(243, 231, 211, 0.3));
  color: var(--ink-50);
  font-size: 9px;
  font-weight: 600;
  font-family: var(--font-sans), sans-serif;
  line-height: 1;
  cursor: help;
  user-select: none;
  outline: none;
  transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
  vertical-align: middle;
  /* Reset any inherited transforms from parent text styling so the
     tooltip glyph reads as a normal "?". */
  text-transform: none;
  letter-spacing: 0;
}
.help-hint:hover,
.help-hint:focus-visible {
  color: var(--ink-100);
  border-color: var(--ink-70, rgba(243, 231, 211, 0.7));
  background: var(--bg-2);
}
</style>

<style>
/* Unscoped: the bubble lives in <body> via Teleport, so scoped styles
   would be stripped from it. Namespaced under .help-hint-bubble to
   stay isolated. */
.help-hint-bubble {
  position: fixed;
  padding: 8px 10px;
  background: var(--bg-0);
  color: var(--ink-100);
  border: 1px solid var(--hairline-strong);
  border-radius: var(--radius-sm, 4px);
  font-family: var(--font-sans), sans-serif;
  font-size: 11px;
  font-weight: 400;
  line-height: 1.45;
  letter-spacing: 0.01em;
  white-space: normal;
  text-align: left;
  /* Defeat any parent text-transform (e.g. the deck sidebar's <h4>
     uppercase rule that would otherwise shout the tooltip). */
  text-transform: none;
  z-index: 9999;
  pointer-events: none;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
}
</style>
