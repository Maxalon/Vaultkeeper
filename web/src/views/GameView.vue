<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useGameStore } from '../stores/game'

const router = useRouter()
const game = useGameStore()

const seats = computed(() => game.seats)

function endGame() {
  game.reset()
  router.push({ name: 'game-setup' })
}
</script>

<template>
  <main class="game-page">
    <header class="game-header">
      <span class="game-title">Game in Progress</span>
      <button class="end-btn" @click="endGame">End Game</button>
    </header>

    <div class="seats-grid" :data-count="seats.length">
      <div v-for="(seat, i) in seats" :key="i" class="seat-card">
        <div class="player-name">{{ seat.name }}</div>
        <div class="life-display">{{ seat.life }}</div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.game-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  display: flex;
  flex-direction: column;
}

.game-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  border-bottom: 1px solid var(--hairline);
}

.game-title {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: var(--ink-70);
  text-transform: uppercase;
}

.end-btn {
  height: 30px;
  padding: 0 16px;
  background: transparent;
  border: 1px solid color-mix(in oklab, #d46a6a 50%, var(--hairline));
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.12s ease;
}
.end-btn:hover {
  background: #d46a6a;
  color: #1a1408;
  border-color: #d46a6a;
}

.seats-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 2px;
  padding: 2px;
}
.seats-grid[data-count="3"],
.seats-grid[data-count="5"],
.seats-grid[data-count="6"] {
  grid-template-columns: repeat(3, 1fr);
}

.seat-card {
  background: var(--bg-1);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 32px;
}

.player-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-70);
  letter-spacing: 0.04em;
}

.life-display {
  font-family: var(--font-display), serif;
  font-size: 80px;
  font-weight: 400;
  color: var(--amber);
  line-height: 1;
}
</style>
