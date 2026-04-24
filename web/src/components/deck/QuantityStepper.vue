<script setup>
defineProps({
  value: { type: Number, required: true },
})
defineEmits(['dec', 'inc'])
</script>

<template>
  <div class="quantity-stepper">
    <button type="button" class="step" @click="$emit('dec')" :disabled="value <= 1">−</button>
    <span class="value">{{ value }}</span>
    <button type="button" class="step" @click="$emit('inc')">+</button>
  </div>
</template>

<style scoped>
.quantity-stepper {
  display: inline-flex;
  align-items: center;
  gap: 0;
  border: 1px solid var(--hairline, #33312c);
  border-radius: 4px;
  overflow: hidden;
}
.step {
  background: transparent;
  border: none;
  color: inherit;
  width: 28px;
  height: 28px;
  font-size: 1rem;
  cursor: pointer;
}
.step:hover { background: var(--bg-2, #26241f); }
.step:disabled { opacity: 0.4; cursor: not-allowed; }
.value {
  padding: 0 0.6rem;
  min-width: 28px;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
</style>
