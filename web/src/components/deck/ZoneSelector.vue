<script setup>
defineProps({
  value: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})
defineEmits(['change'])

const ZONES = [
  { key: 'main',  label: 'Main' },
  { key: 'side',  label: 'Side' },
  { key: 'maybe', label: 'Maybe' },
]
</script>

<template>
  <div class="zone-selector" role="radiogroup">
    <button
      v-for="z in ZONES"
      :key="z.key"
      type="button"
      class="zone-btn"
      :class="{ active: value === z.key }"
      :disabled="disabled"
      @click="$emit('change', z.key)"
    >{{ z.label }}</button>
  </div>
</template>

<style scoped>
.zone-selector {
  display: inline-flex;
  border: 1px solid var(--hairline, #33312c);
  border-radius: 4px;
  overflow: hidden;
}
.zone-btn {
  background: transparent;
  border: none;
  color: var(--ink-70, #a8a396);
  padding: 0.35rem 0.8rem;
  cursor: pointer;
  font-size: 0.85rem;
}
.zone-btn.active {
  background: var(--amber, #c99d3d);
  color: #1a1a22;
}
.zone-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.zone-btn:not(:last-child) {
  border-right: 1px solid var(--hairline, #33312c);
}
</style>
