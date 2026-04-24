<script setup>
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'

const props = defineProps({
  zone: { type: String, required: true },  // 'side' | 'maybe'
  label: { type: String, default: '' },
})

const deck = useDeckStore()
const tabs = useTabsStore()

function onUndock() {
  tabs.undockSection(props.zone)
  deck.setUndocked(props.zone, true)
}
</script>

<template>
  <div class="zone-divider">
    <span class="zone-label">{{ label || (zone === 'side' ? 'Sideboard' : 'Maybeboard') }}</span>
    <button type="button" class="undock-btn" @click="onUndock" title="Undock to own tab">↑</button>
  </div>
</template>

<style scoped>
.zone-divider {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 1.25rem;
  border-top: 1px solid var(--hairline, #33312c);
  border-bottom: 1px solid var(--hairline, #33312c);
  background: var(--bg-2, #26241f);
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ink-70, #a8a396);
  margin-top: 1rem;
}
.undock-btn {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  cursor: pointer;
}
.undock-btn:hover { color: var(--amber, #c99d3d); }
</style>
