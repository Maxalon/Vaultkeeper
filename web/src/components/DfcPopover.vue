<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Hovering-anchored popover showing the back face of a double-faced card.
 * Positioned relative to its anchor element (usually a CardTile root).
 * Clamps to the viewport edges so it never crops offscreen.
 *
 * Consumer is responsible for the hover state — mount this when hovered,
 * pass the anchor element, let it teleport itself to <body>.
 */
const props = defineProps({
  backImage: { type: String, default: null },
  anchor: { type: Object, default: null }, // HTMLElement | null
})

const rect = ref({ top: 0, left: 0 })

function recompute() {
  const a = props.anchor
  if (!a || typeof a.getBoundingClientRect !== 'function') return
  const r = a.getBoundingClientRect()
  // Preferred placement: to the right of the tile, roughly same vertical.
  const POP_W = 240
  const POP_H = 336
  const GAP = 8
  let left = r.right + GAP
  let top = r.top
  if (left + POP_W > window.innerWidth - 8) {
    left = Math.max(8, r.left - POP_W - GAP)
  }
  if (top + POP_H > window.innerHeight - 8) {
    top = Math.max(8, window.innerHeight - POP_H - 8)
  }
  rect.value = { top, left }
}

onMounted(() => {
  recompute()
  window.addEventListener('scroll', recompute, true)
  window.addEventListener('resize', recompute)
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', recompute, true)
  window.removeEventListener('resize', recompute)
})

const style = computed(() => ({
  top: `${rect.value.top}px`,
  left: `${rect.value.left}px`,
}))
</script>

<template>
  <Teleport to="body">
    <div class="dfc-popover" :style="style">
      <img v-if="backImage" :src="backImage" alt="Back face" />
    </div>
  </Teleport>
</template>

<style scoped>
.dfc-popover {
  position: fixed;
  width: 240px;
  aspect-ratio: 63 / 88;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5);
  background: #1a1a22;
  z-index: 100;
  pointer-events: none;
  animation: pop-in 120ms ease-out;
}
.dfc-popover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

@keyframes pop-in {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>
