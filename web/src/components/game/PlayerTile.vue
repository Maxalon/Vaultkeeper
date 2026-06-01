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

// ── Delta indicator ────────────────────────────────────────────────
const deltaFlashes = ref([])   // [{ id, text, key }]
let flashSeq = 0

function showDelta(delta) {
  const id = ++flashSeq
  deltaFlashes.value.push({ id, text: delta > 0 ? `+${delta}` : `${delta}` })
  setTimeout(() => {
    const idx = deltaFlashes.value.findIndex((f) => f.id === id)
    if (idx !== -1) deltaFlashes.value.splice(idx, 1)
  }, 900)
}

// ── Hold-to-accelerate ─────────────────────────────────────────────
let holdTimer = null
let holdInterval = null
let holdStartMs = 0

function startHold(sign) {
  holdStartMs = Date.now()

  // Immediate single step.
  applyDelta(sign)

  // After 1 s: 5/s; after 3 s: 10/s
  holdTimer = setTimeout(() => {
    holdInterval = setInterval(() => {
      const elapsed = Date.now() - holdStartMs
      const rate = elapsed >= 3000 ? 10 : 5
      applyDelta(sign * rate)
    }, 1000)
  }, 1000)
}

function applyDelta(delta) {
  emit('adjust', delta)
  showDelta(delta)
}

function stopHold() {
  clearTimeout(holdTimer)
  clearInterval(holdInterval)
  holdTimer = null
  holdInterval = null
}

// Upper half = +1 from the current player's perspective.
// When the tile is rotated, the physical upper half is the bottom for
// the player, so the sign flips.
function upperSign() { return isRotated.value ? -1 : +1 }
function lowerSign() { return isRotated.value ? +1 : -1 }

function onUpperDown(e) {
  e.preventDefault()
  startHold(upperSign())
}
function onLowerDown(e) {
  e.preventDefault()
  startHold(lowerSign())
}
</script>

<template>
  <div class="player-tile" :class="{ rotated: isRotated }" data-testid="player-tile">
    <div class="tile-inner">
      <div
        class="tap-upper"
        data-testid="tap-upper"
        @pointerdown="onUpperDown"
        @pointerup="stopHold"
        @pointerleave="stopHold"
        @pointercancel="stopHold"
      />
      <div class="tile-body">
        <span class="player-name">{{ name }}</span>
        <span class="life-total" :class="{ dead: life <= 0 }" data-testid="life-total">{{ life }}</span>
        <div class="delta-stage" aria-hidden="true">
          <TransitionGroup name="delta">
            <span
              v-for="f in deltaFlashes"
              :key="f.id"
              class="delta-flash"
              :class="f.text.startsWith('+') ? 'pos' : 'neg'"
            >{{ f.text }}</span>
          </TransitionGroup>
        </div>
      </div>
      <div
        class="tap-lower"
        data-testid="tap-lower"
        @pointerdown="onLowerDown"
        @pointerup="stopHold"
        @pointerleave="stopHold"
        @pointercancel="stopHold"
      />
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
  gap: 4px;
  padding: 0 16px;
  flex-shrink: 0;
  pointer-events: none;
  position: relative;
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

/* ── Delta indicator ───────────────────────────────────────────── */
.delta-stage {
  position: absolute;
  top: -10px;
  right: -8px;
  pointer-events: none;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}

.delta-flash {
  font-size: 18px;
  font-weight: 700;
  font-family: var(--font-sans), sans-serif;
  line-height: 1;
}
.delta-flash.pos { color: var(--green, #5eb870); }
.delta-flash.neg { color: var(--red, #e05252); }

.delta-enter-active { animation: deltaIn 0.15s ease; }
.delta-leave-active { animation: deltaOut 0.6s ease forwards; }

@keyframes deltaIn {
  from { opacity: 0; transform: translateY(6px) scale(0.8); }
  to   { opacity: 1; transform: translateY(0)   scale(1); }
}
@keyframes deltaOut {
  0%   { opacity: 1; transform: translateY(0); }
  100% { opacity: 0; transform: translateY(-18px); }
}

/* ── Rotation button ───────────────────────────────────────────── */
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
