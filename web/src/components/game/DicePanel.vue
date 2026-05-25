<script setup>
import { ref } from 'vue'
import { useGameStore } from '../../stores/game'

const emit = defineEmits(['close'])

const game = useGameStore()

const DICE = [
  { label: 'd4',   faces: 4 },
  { label: 'd6',   faces: 6 },
  { label: 'd8',   faces: 8 },
  { label: 'd10',  faces: 10 },
  { label: 'd12',  faces: 12 },
  { label: 'd20',  faces: 20 },
  { label: 'Coin', faces: 0 },
]

const rolling = ref(false)
const resultLabel = ref('')   // 'd20'
const resultValue = ref('')   // '17' or 'Heads'

let rollSeq = 0

async function roll(die) {
  if (rolling.value) return
  rolling.value = true
  resultLabel.value = ''
  resultValue.value = ''

  const seq = ++rollSeq

  // Brief spin: show random intermediate values for 500 ms.
  const spinMs = 500
  const spinStart = Date.now()
  const spinFaces = die.faces || 2
  let spinFrame

  function spin() {
    if (Date.now() - spinStart < spinMs && rollSeq === seq) {
      resultLabel.value = die.label
      resultValue.value = die.faces
        ? String(Math.floor(Math.random() * die.faces) + 1)
        : (Math.random() < 0.5 ? 'Heads' : 'Tails')
      spinFrame = requestAnimationFrame(spin)
    } else {
      cancelAnimationFrame(spinFrame)
      if (rollSeq !== seq) return
      const actual = die.faces ? game.rollDice(die.faces) : game.flipCoin()
      resultLabel.value = die.label
      resultValue.value = String(actual)
      rolling.value = false
    }
  }

  spinFrame = requestAnimationFrame(spin)
}
</script>

<template>
  <div class="dice-overlay" role="dialog" aria-label="Dice roller" @click.self="emit('close')">
    <div class="dice-panel">
      <button class="close-btn" aria-label="Dismiss" @click="emit('close')">✕</button>

      <div v-if="resultValue" class="result-display" :class="{ rolling }">
        <span class="result-label">{{ resultLabel }}</span>
        <span class="result-value">{{ resultValue }}</span>
      </div>
      <div v-else class="result-placeholder" aria-hidden="true" />

      <div class="dice-grid">
        <button
          v-for="die in DICE"
          :key="die.label"
          class="die-btn"
          :disabled="rolling"
          :aria-label="`Roll ${die.label}`"
          @click="roll(die)"
        >{{ die.label }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dice-overlay {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
}

.dice-panel {
  background: rgba(18, 18, 18, 0.97);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 16px;
  padding: 20px 20px 16px;
  width: min(320px, 90vw);
  display: flex;
  flex-direction: column;
  gap: 16px;
  position: relative;
}

.close-btn {
  position: absolute;
  top: 10px;
  right: 12px;
  background: transparent;
  border: none;
  color: rgba(255, 255, 255, 0.35);
  font-size: 14px;
  cursor: pointer;
  padding: 4px 6px;
  line-height: 1;
  transition: color 0.15s ease;
}
.close-btn:hover { color: rgba(255, 255, 255, 0.75); }

.result-display {
  text-align: center;
  min-height: 72px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
}
.result-placeholder {
  min-height: 72px;
}

.result-display.rolling .result-value {
  animation: spin 0.08s linear infinite;
}

.result-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.4);
  font-family: var(--font-sans), sans-serif;
}

.result-value {
  font-family: var(--font-display, serif);
  font-size: clamp(40px, 10vmin, 56px);
  font-weight: 400;
  color: var(--amber, #f5c842);
  line-height: 1;
}

@keyframes spin {
  0%   { opacity: 1; }
  50%  { opacity: 0.4; }
  100% { opacity: 1; }
}

.dice-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

/* Last row: d20 + Coin span to fill 4 columns (2 items × 2 cols each) */
.dice-grid .die-btn:nth-child(6),
.dice-grid .die-btn:nth-child(7) {
  grid-column: span 2;
}

.die-btn {
  height: 44px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  color: rgba(255, 255, 255, 0.7);
  font-size: 13px;
  font-weight: 700;
  font-family: var(--font-sans), sans-serif;
  letter-spacing: 0.05em;
  cursor: pointer;
  transition: all 0.12s ease;
}

.die-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.25);
  color: #fff;
}

.die-btn:disabled {
  opacity: 0.4;
  cursor: default;
}
</style>
