<script setup>
import { ref } from 'vue'

const props = defineProps({
  seats: { type: Array, required: true },
})

const emit = defineEmits(['confirm', 'cancel'])

// null = no selection yet; -1 = draw; >= 0 = seat index
const selectedWinner = ref(null)

function selectWinner(index) {
  selectedWinner.value = index
}

function onConfirm() {
  if (selectedWinner.value === null) return
  emit('confirm', selectedWinner.value === -1 ? null : selectedWinner.value)
}
</script>

<template>
  <div class="summary-backdrop">
    <div class="summary-sheet">
      <h2 class="summary-title">Game Over</h2>

      <p class="summary-hint">Tap a player to mark as winner, or select Draw.</p>

      <ul class="seat-list">
        <li
          v-for="(seat, i) in seats"
          :key="i"
          class="seat-row"
          :class="{ selected: selectedWinner === i }"
          @click="selectWinner(i)"
        >
          <span class="seat-name">{{ seat.name }}</span>
          <span class="seat-life" :class="{ dead: seat.life <= 0 }">{{ seat.life }}</span>
          <span v-if="seat.deckId" class="seat-deck-indicator" title="Deck selected" />
        </li>
      </ul>

      <button
        class="draw-btn"
        :class="{ selected: selectedWinner === -1 }"
        @click="selectWinner(-1)"
      >
        No Winner / Draw
      </button>

      <div class="summary-actions">
        <button class="cancel-btn" @click="emit('cancel')">Cancel</button>
        <button
          class="confirm-btn"
          :disabled="selectedWinner === null"
          @click="onConfirm"
        >
          Confirm
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.summary-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.72);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}

.summary-sheet {
  background: var(--bg-1, #1c1c1c);
  border: 1px solid var(--hairline, #2e2e2e);
  border-radius: 12px;
  padding: 28px 28px 24px;
  width: min(420px, calc(100vw - 32px));
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.summary-title {
  font-family: var(--font-display, serif);
  font-size: 26px;
  font-weight: 400;
  color: var(--amber, #f5c842);
  margin: 0;
  letter-spacing: -0.01em;
}

.summary-hint {
  font-size: 12px;
  color: var(--ink-50, #777);
  margin: 0;
  letter-spacing: 0.02em;
}

.seat-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.seat-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 6px;
  border: 1px solid var(--hairline, #2e2e2e);
  cursor: pointer;
  transition: border-color 0.12s ease, background 0.12s ease;
  background: var(--bg-2, #242424);
}

.seat-row:hover {
  border-color: var(--ink-30, #555);
}

.seat-row.selected {
  border-color: var(--amber, #f5c842);
  background: rgba(245, 200, 66, 0.08);
}

.seat-name {
  flex: 1;
  font-size: 14px;
  color: var(--ink-100, #f0f0f0);
  font-family: var(--font-sans, sans-serif);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.seat-life {
  font-family: var(--font-display, serif);
  font-size: 22px;
  color: var(--amber, #f5c842);
  min-width: 32px;
  text-align: right;
}

.seat-life.dead {
  color: var(--red, #e05252);
}

.seat-deck-indicator {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--ink-30, #555);
  flex-shrink: 0;
  title: "Has deck";
}

.draw-btn {
  width: 100%;
  padding: 10px;
  background: var(--bg-2, #242424);
  border: 1px solid var(--hairline, #2e2e2e);
  border-radius: 6px;
  color: var(--ink-70, #aaa);
  font-size: 13px;
  font-family: var(--font-sans, sans-serif);
  cursor: pointer;
  letter-spacing: 0.04em;
  transition: border-color 0.12s ease, background 0.12s ease;
  text-align: center;
}

.draw-btn:hover {
  border-color: var(--ink-30, #555);
  color: var(--ink-100, #f0f0f0);
}

.draw-btn.selected {
  border-color: var(--ink-50, #777);
  background: rgba(255, 255, 255, 0.06);
  color: var(--ink-100, #f0f0f0);
}

.summary-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}

.cancel-btn {
  height: 36px;
  padding: 0 18px;
  background: transparent;
  border: 1px solid var(--hairline, #2e2e2e);
  border-radius: 6px;
  color: var(--ink-50, #777);
  font-size: 13px;
  font-family: var(--font-sans, sans-serif);
  cursor: pointer;
  transition: color 0.12s ease, border-color 0.12s ease;
}

.cancel-btn:hover:not(:disabled) {
  color: var(--ink-100, #f0f0f0);
  border-color: var(--ink-30, #555);
}

.cancel-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.confirm-btn {
  height: 36px;
  padding: 0 22px;
  background: var(--amber, #f5c842);
  border: 0;
  border-radius: 6px;
  color: #1a1408;
  font-size: 13px;
  font-weight: 700;
  font-family: var(--font-sans, sans-serif);
  letter-spacing: 0.06em;
  cursor: pointer;
  transition: background 0.12s ease, opacity 0.12s ease;
}

.confirm-btn:hover:not(:disabled) {
  background: var(--amber-hi, #f7d265);
}

.confirm-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}
</style>
