<script setup>
defineProps({
  text: { type: String, required: true },
  position: {
    type: String,
    default: 'top',
    validator: (v) => ['top', 'bottom', 'right'].includes(v),
  },
  width: { type: Number, default: 240 },
})
</script>

<template>
  <span
    class="help-hint"
    :class="`pos-${position}`"
    :data-tip="text"
    :style="{ '--hint-width': `${width}px` }"
    tabindex="0"
    role="tooltip"
    aria-label="Help"
  >?</span>
</template>

<style scoped>
.help-hint {
  position: relative;
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
}
.help-hint:hover,
.help-hint:focus-visible {
  color: var(--ink-100);
  border-color: var(--ink-70, rgba(243, 231, 211, 0.7));
  background: var(--bg-2);
}

/* Tooltip bubble — pure CSS hover. No transition-delay so it appears
   instantly, fixing the perceptible lag on the native title="" pattern. */
.help-hint::after {
  content: attr(data-tip);
  position: absolute;
  width: max-content;
  max-width: var(--hint-width, 240px);
  padding: 8px 10px;
  background: var(--bg-0);
  color: var(--ink-100);
  border: 1px solid var(--hairline-strong);
  border-radius: var(--radius-sm, 4px);
  font-size: 11px;
  font-weight: 400;
  line-height: 1.45;
  letter-spacing: 0.01em;
  white-space: normal;
  text-align: left;
  z-index: 200;
  pointer-events: none;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
  opacity: 0;
  visibility: hidden;
}
.help-hint::before {
  content: '';
  position: absolute;
  width: 0;
  height: 0;
  z-index: 201;
  pointer-events: none;
  opacity: 0;
  visibility: hidden;
}

.help-hint:hover::after,
.help-hint:focus-visible::after,
.help-hint:hover::before,
.help-hint:focus-visible::before {
  opacity: 1;
  visibility: visible;
}

/* Position variants */
.pos-top::after {
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
}
.pos-top::before {
  bottom: calc(100% + 1px);
  left: 50%;
  transform: translateX(-50%);
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-top: 5px solid var(--hairline-strong);
}

.pos-bottom::after {
  top: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
}
.pos-bottom::before {
  top: calc(100% + 1px);
  left: 50%;
  transform: translateX(-50%);
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-bottom: 5px solid var(--hairline-strong);
}

.pos-right::after {
  left: calc(100% + 6px);
  top: 50%;
  transform: translateY(-50%);
}
.pos-right::before {
  left: calc(100% + 1px);
  top: 50%;
  transform: translateY(-50%);
  border-top: 5px solid transparent;
  border-bottom: 5px solid transparent;
  border-right: 5px solid var(--hairline-strong);
}
</style>
