<script setup>
defineProps({
  card: { type: Object, required: true },
  label: { type: String, default: '' },
})
</script>

<template>
  <div class="commander-strip">
    <img
      v-if="card.image_normal || card.image_small"
      :src="card.image_normal || card.image_small"
      :alt="card.name"
    />
    <div v-else class="strip-fallback">{{ card.name }}</div>
    <span v-if="label" class="strip-label">{{ label }}</span>
  </div>
</template>

<style scoped>
.commander-strip {
  position: relative;
  height: 40px;
  border-radius: 6px 6px 0 0;
  overflow: hidden;
  background: #000;
  border: 1px solid #0a0a0a;
  transition: height 150ms ease;
}
.commander-strip:hover { height: auto; aspect-ratio: 63/88; }
.commander-strip img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: top;
  display: block;
}
.strip-fallback {
  padding: 0.4rem;
  font-family: var(--font-display), serif;
  font-size: 0.8rem;
}
.strip-label {
  position: absolute;
  left: 4px;
  top: 4px;
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: rgba(0, 0, 0, 0.6);
  padding: 1px 6px;
  border-radius: 3px;
  color: var(--ink-70, #a8a396);
}
</style>
