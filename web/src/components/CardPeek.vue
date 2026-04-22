<script setup>
import { computed } from 'vue'

const props = defineProps({
  entry: { type: Object, default: null },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  visible: { type: Boolean, default: false },
})

const card = computed(() => props.entry?.card || null)
const isDfc = computed(() =>
  !!(card.value && card.value.is_dfc && card.value.image_normal_back)
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="card"
      class="vk-peek"
      :class="{ visible, 'is-dfc': isDfc }"
      :style="{ left: x + 'px', top: y + 'px' }"
    >
      <template v-if="isDfc">
        <img class="face" :src="card.image_normal" :alt="card.name" />
        <img class="face" :src="card.image_normal_back" :alt="card.name + ' (back)'" />
      </template>
      <template v-else>
        <img v-if="card.image_normal" :src="card.image_normal" :alt="card.name" />
        <div v-else class="placeholder">{{ card.name }}</div>
      </template>
    </div>
  </Teleport>
</template>

<style scoped>
.vk-peek {
  position: fixed;
  pointer-events: none;
  z-index: 1000;
  width: var(--card-width);
  aspect-ratio: 63 / 88;
  border-radius: 12px;
  opacity: 0;
  transform: translateY(-4px) scale(0.96);
  transition: opacity 0.18s ease, transform 0.18s ease;
  box-shadow:
    0 0 0 1px rgba(240, 195, 92, 0.3),
    0 24px 48px rgba(0, 0, 0, 0.6),
    0 12px 24px rgba(0, 0, 0, 0.4);
  overflow: hidden;
  background: var(--vk-bg-2);
}
/* DFC peek shows both faces side-by-side; width doubles and the inner
   aspect-ratio rule is dropped so the container naturally grows wide.
   Each face keeps its own 63:88 aspect. An 8px gap matches DfcPopover.vue's
   GAP so the catalog and collection visuals stay in sync. */
.vk-peek.is-dfc {
  width: calc(var(--card-width) * 2 + 8px);
  aspect-ratio: auto;
  display: flex;
  gap: 8px;
  background: transparent;
  box-shadow: none;
  border-radius: 0;
  overflow: visible;
}
.vk-peek.visible {
  opacity: 1;
  transform: translateY(0) scale(1);
}
img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.vk-peek.is-dfc .face {
  width: var(--card-width);
  aspect-ratio: 63 / 88;
  height: auto;
  border-radius: 12px;
  box-shadow:
    0 0 0 1px rgba(240, 195, 92, 0.3),
    0 24px 48px rgba(0, 0, 0, 0.6),
    0 12px 24px rgba(0, 0, 0, 0.4);
  background: var(--vk-bg-2);
}
.placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 18px;
  color: var(--vk-ink-2);
  font-family: var(--font-display), serif;
  font-size: 16px;
  text-align: center;
}
</style>
