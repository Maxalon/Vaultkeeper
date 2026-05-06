<script setup>
import { computed } from 'vue'

/**
 * Site-styled checkbox. Uses a real <input type="checkbox"> for keyboard
 * + screen-reader semantics, but hides it visually and renders a styled
 * box + check mark next to a label slot.
 *
 * Two-way bind via `v-model`:
 *   <Checkbox v-model="checked" label="All cards are present" />
 *
 * Or use the slot for non-string labels:
 *   <Checkbox v-model="checked"><strong>X</strong> ×3 of 4</Checkbox>
 */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  label: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  // Optional muted hint shown beneath the label, like the picker
  // sub-text in §3.7's product copy ("Adds a new copy in this deck's
  // storage." etc).
  hint: { type: String, default: '' },
  // Forwarded to the inner <input> for accessibility / form scoping.
  name: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const checked = computed({
  get: () => !!props.modelValue,
  set: (v) => emit('update:modelValue', !!v),
})

function onChange(e) {
  checked.value = e.target.checked
}
</script>

<template>
  <label class="vk-checkbox" :class="{ disabled }">
    <input
      type="checkbox"
      :name="name || undefined"
      :checked="checked"
      :disabled="disabled"
      @change="onChange"
    />
    <span class="box" aria-hidden="true">
      <svg class="check" viewBox="0 0 12 12" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 6.5l2.6 2.6L10 3.5" />
      </svg>
    </span>
    <span class="content">
      <span v-if="label" class="label-text">{{ label }}</span>
      <slot v-else />
      <span v-if="hint" class="hint">{{ hint }}</span>
    </span>
  </label>
</template>

<style scoped>
.vk-checkbox {
  display: inline-flex;
  align-items: flex-start;
  gap: 8px;
  cursor: pointer;
  font-size: 13px;
  color: var(--ink-100);
  user-select: none;
  line-height: 1.3;
}
.vk-checkbox.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Real input — kept in the DOM for keyboard + screen-reader support but
   visually hidden so we can style the .box ourselves. */
.vk-checkbox input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  pointer-events: none;
}

.box {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  margin-top: 1px;
  border-radius: 3px;
  background: var(--bg-0);
  border: 1px solid var(--hairline-strong, var(--hairline));
  color: transparent;
  transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}
.vk-checkbox:hover:not(.disabled) .box {
  border-color: var(--amber);
}
.vk-checkbox input:checked ~ .box {
  background: var(--amber);
  border-color: var(--amber);
  color: #1a120c;
}
.vk-checkbox input:focus-visible ~ .box {
  outline: 2px solid var(--amber);
  outline-offset: 2px;
}
.check {
  display: block;
}

.content {
  display: inline-flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.label-text {
  display: inline-block;
}
.hint {
  font-size: 11px;
  color: var(--ink-50);
  line-height: 1.4;
}
</style>
