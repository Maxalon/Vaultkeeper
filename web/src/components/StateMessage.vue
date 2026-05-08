<script setup>
defineProps({
  variant: {
    type: String,
    default: 'info',
    validator: (v) => ['info', 'error'].includes(v),
  },
})
</script>

<template>
  <div class="state-msg" :class="{ error: variant === 'error' }">
    <slot />
  </div>
</template>

<style scoped>
.state-msg {
  padding: 48px 20px;
  text-align: center;
  color: var(--ink-50);
  font-size: 14px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
}
.state-msg.error { color: #d46a6a; }
</style>
