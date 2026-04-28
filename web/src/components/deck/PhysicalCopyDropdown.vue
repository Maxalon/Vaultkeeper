<script setup>
import { computed, watch } from 'vue'
import { useDeckStore } from '../../stores/deck'

const props = defineProps({
  scryfallId: { type: String, required: true },
  value: { type: Number, default: null },
})
const emit = defineEmits(['select'])

const deck = useDeckStore()

const options = computed(() => deck.ownedCopiesByScryfallId[props.scryfallId] || [])

watch(
  () => props.scryfallId,
  (id) => { if (id) deck.loadOwnedCopies(id) },
  { immediate: true },
)

function onChange(e) {
  const v = e.target.value
  emit('select', v === '' ? null : Number(v))
}
</script>

<template>
  <select class="physical-copy-dropdown" name="physical-copy" :value="value ?? ''" @change="onChange">
    <option value="">— none —</option>
    <option v-for="opt in options" :key="opt.id" :value="opt.id">
      {{ opt.location_name || 'Unassigned' }} · qty {{ opt.quantity }}{{ opt.foil ? ' ·F' : '' }}
    </option>
  </select>
</template>

<style scoped>
.physical-copy-dropdown {
  width: 100%;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 0.4rem 0.6rem;
  font-size: 0.85rem;
  border-radius: 4px;
}
</style>
