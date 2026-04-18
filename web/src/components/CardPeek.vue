<script setup>
import { computed } from 'vue'

const props = defineProps({
  entry: { type: Object, default: null },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  visible: { type: Boolean, default: false },
})

const card = computed(() => props.entry?.card || null)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="card"
      class="vk-peek"
      :class="{ visible }"
      :style="{ left: x + 'px', top: y + 'px' }"
    >
      <img v-if="card.image_normal" :src="card.image_normal" :alt="card.name" />
      <div v-else class="placeholder">{{ card.name }}</div>
    </div>
  </Teleport>
</template>

<style scoped>
.vk-peek {
  position: fixed;
  pointer-events: none;
  z-index: 1000;
  width: 240px;
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
