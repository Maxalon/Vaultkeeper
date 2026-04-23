<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: String, default: '' },
  options: { type: Array, required: true }, // [{ k, n }]
  tokenClass: { type: String, default: '' },
  align: { type: String, default: 'left' }, // 'left' | 'right'
  hintPrefix: { type: String, default: '' }, // shown next to each option as a tiny mono hint
  variant: { type: String, default: 'default' }, // 'default' | 'ghost' | 'solid'
})

const emit = defineEmits(['change'])

const open = ref(false)
const wrap = ref(null)

function toggle() { open.value = !open.value }
function pick(k) { emit('change', k); open.value = false }

function onDocClick(event) {
  if (!open.value) return
  if (wrap.value && !wrap.value.contains(event.target)) open.value = false
}

watch(open, (v) => {
  if (v) document.addEventListener('mousedown', onDocClick)
  else document.removeEventListener('mousedown', onDocClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
})

function selectedOption() {
  return props.options.find((o) => o.k === props.value)
}

function displayLabel() {
  const sel = selectedOption()
  return sel && sel.k ? sel.n : props.label
}

function isActive() {
  const sel = selectedOption()
  return !!(sel && sel.k)
}
</script>

<template>
  <div ref="wrap" class="vk-chip-wrap">
    <button
      class="vk-chip"
      :class="[
        { active: isActive(), open },
        variant !== 'default' ? `vk-chip--${variant}` : '',
      ]"
      @click="toggle"
    >
      <span v-if="variant === 'solid'" class="dot-accent" />
      <span v-else-if="isActive()" class="tok-dot" :class="tokenClass" />
      {{ displayLabel() }} <span class="caret">▼</span>
    </button>
    <div v-if="open" class="vk-chip-menu" :class="{ right: align === 'right' }">
      <button
        v-for="o in options"
        :key="o.k || '__none'"
        class="vk-chip-menu-item"
        :class="{ active: o.k === value }"
        @click="pick(o.k)"
      >
        <span class="k">{{ o.n }}</span>
        <span v-if="o.k && hintPrefix" class="tok-hint">{{ hintPrefix }}{{ o.k }}</span>
      </button>
    </div>
  </div>
</template>
