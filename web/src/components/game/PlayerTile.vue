<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
  life: { type: Number, required: true },
  autoRotate: { type: Boolean, default: false },
})

const emit = defineEmits(['adjust'])

const userRotated = ref(false)
const isRotated = computed(() => props.autoRotate !== userRotated.value)

function toggleRotate() {
  userRotated.value = !userRotated.value
}
</script>

<template>
  <div class="player-tile" :class="{ rotated: isRotated }" data-testid="player-tile">
    <div class="tile-inner">
      <div class="tap-upper" data-testid="tap-upper" />
      <div class="tile-body">
        <span class="player-name">{{ name }}</span>
        <span class="life-total" :class="{ dead: life <= 0 }" data-testid="life-total">{{ life }}</span>
      </div>
      <div class="tap-lower" data-testid="tap-lower" />
    </div>
    <button
      class="rotate-btn"
      :class="{ active: userRotated }"
      data-testid="rotate-btn"
      aria-label="Rotate tile 180°"
      @click.stop="toggleRotate"
    >↺</button>
  </div>
</template>

<style scoped>
.player-tile {
  position: relative;
  background: var(--bg-1, #1c1c1c);
  overflow: hidden;
  user-select: none;
  -webkit-user-select: none;
  touch-action: none;
  contain: layout style;
}

.tile-inner {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  height: 100%;
  transition: transform 280ms ease;
}

.player-tile.rotated .tile-inner {
  transform: rotate(180deg);
}

.tap-upper,
.tap-lower {
  flex: 1;
  cursor: pointer;
  min-height: 0;
}

.tile-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 16px;
  flex-shrink: 0;
  pointer-events: none;
}

.player-name {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-50, #777);
  font-family: var(--font-sans), sans-serif;
}

.life-total {
  font-family: var(--font-display, serif);
  font-size: clamp(52px, 10vmin, 100px);
  font-weight: 400;
  color: var(--amber, #f5c842);
  line-height: 1;
}

.life-total.dead {
  color: var(--red, #e05252);
}

.rotate-btn {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 28px;
  height: 28px;
  background: transparent;
  border: 1px solid var(--hairline, #2e2e2e);
  border-radius: 50%;
  color: var(--ink-30, #555);
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  line-height: 1;
  transition: all 150ms ease;
  z-index: 2;
}

.rotate-btn:hover,
.rotate-btn.active {
  color: var(--ink-70, #aaa);
  border-color: var(--ink-30, #555);
}
</style>
