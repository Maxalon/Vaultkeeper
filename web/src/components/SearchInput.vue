<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Search…' },
  debounceMs: { type: Number, default: 250 },
  ariaLabel: { type: String, default: 'Search' },
})

const emit = defineEmits(['update:modelValue', 'debounced'])

const local = ref(props.modelValue)
let timer = null

watch(() => props.modelValue, (v) => { local.value = v })

function onInput(e) {
  local.value = e.target.value
  emit('update:modelValue', local.value)
  clearTimeout(timer)
  timer = setTimeout(() => emit('debounced', local.value), props.debounceMs)
}
</script>

<template>
  <input
    type="text"
    :value="local"
    :placeholder="placeholder"
    :aria-label="ariaLabel"
    class="search-input"
    @input="onInput"
  />
</template>

<style scoped>
.search-input {
  width: 100%;
  max-width: 400px;
  height: 36px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  padding: 0 12px;
  outline: none;
  box-sizing: border-box;
  transition: border-color 0.12s ease;
}
.search-input:focus { border-color: var(--amber); }
.search-input::placeholder { color: var(--ink-30); }
</style>
