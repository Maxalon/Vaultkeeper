<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  value: { type: String, default: '' },
  suggestions: { type: Array, default: () => [] },
})
const emit = defineEmits(['commit'])

const draft = ref(props.value || '')
const open = ref(false)

const filtered = computed(() => {
  const q = draft.value.toLowerCase()
  return props.suggestions
    .filter((s) => s.toLowerCase().includes(q) && s !== draft.value)
    .slice(0, 8)
})

function commit() {
  emit('commit', draft.value.trim())
  open.value = false
}

function pick(s) {
  draft.value = s
  commit()
}
</script>

<template>
  <div class="category-input">
    <input
      type="text"
      :value="draft"
      @input="draft = $event.target.value; open = true"
      @focus="open = true"
      @blur="commit"
      @keydown.enter="commit"
      placeholder="Category…"
    />
    <ul v-if="open && filtered.length" class="suggestions">
      <li v-for="s in filtered" :key="s" @mousedown.prevent="pick(s)">{{ s }}</li>
    </ul>
  </div>
</template>

<style scoped>
.category-input { position: relative; }
input {
  width: 100%;
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  color: inherit;
  padding: 0.4rem 0.6rem;
  border-radius: 4px;
  font-size: 0.85rem;
}
.suggestions {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  z-index: 10;
  list-style: none;
  margin: 2px 0 0;
  padding: 0;
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  border-radius: 4px;
  max-height: 180px;
  overflow-y: auto;
}
.suggestions li {
  padding: 0.35rem 0.6rem;
  cursor: pointer;
  font-size: 0.82rem;
}
.suggestions li:hover { background: var(--vk-surface, #1d1c1a); }
</style>
