<script setup>
import { computed } from 'vue'
import ManaSymbol from './ManaSymbol.vue'

const props = defineProps({
  cost: { type: String, default: '' },
})

const symbols = computed(() => {
  if (!props.cost) return []
  return props.cost.match(/\{[^}]+\}/g) || []
})
</script>

<template>
  <span class="mana-cost">
    <ManaSymbol v-for="(s, i) in symbols" :key="i" :symbol="s" />
  </span>
</template>

<style scoped>
.mana-cost {
  display: inline-flex;
  align-items: center;
  gap: 1px;
  white-space: nowrap;
}
</style>
