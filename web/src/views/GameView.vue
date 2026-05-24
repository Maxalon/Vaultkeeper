<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useGameStore } from '../stores/game'
import PlayerTile from '../components/game/PlayerTile.vue'

const router = useRouter()
const game = useGameStore()

const seats = computed(() => game.seats)

function endGame() {
  game.reset()
  router.push({ name: 'game-setup' })
}
</script>

<template>
  <div class="game-view">
    <div class="tiles-grid" :data-count="seats.length">
      <PlayerTile
        v-for="(seat, i) in seats"
        :key="i"
        :name="seat.name"
        :life="seat.life"
        :auto-rotate="seats.length === 2 && i === 1"
        @adjust="(delta) => game.adjustLife(i, delta)"
      />
    </div>
    <button class="end-game-fab" aria-label="End game" @click="endGame">End</button>
  </div>
</template>

<style scoped>
.game-view {
  position: fixed;
  inset: 0;
  background: #0d0d0d;
  overflow: hidden;
}

/* Full-screen tile grid respecting safe-area insets */
.tiles-grid {
  position: absolute;
  inset:
    env(safe-area-inset-top, 0px)
    env(safe-area-inset-right, 0px)
    env(safe-area-inset-bottom, 0px)
    env(safe-area-inset-left, 0px);
  display: grid;
  gap: 2px;
}

/* ── Layout rules ─────────────────────────────────────────────── */
/* 2 players: one column, two rows — bottom tile auto-rotated */
.tiles-grid[data-count="2"] {
  grid-template-columns: 1fr;
  grid-template-rows: 1fr 1fr;
}

/* 3 players: top tile spans full width, two below side-by-side */
.tiles-grid[data-count="3"] {
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
}
.tiles-grid[data-count="3"] :deep(.player-tile:first-child) {
  grid-column: 1 / -1;
}

/* 4 players: 2×2 */
.tiles-grid[data-count="4"] {
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
}

/* 5 players: three top + two bottom.
   6-column base: top tiles span 2, bottom tiles span 3. */
.tiles-grid[data-count="5"] {
  grid-template-columns: repeat(6, 1fr);
  grid-template-rows: 1fr 1fr;
}
.tiles-grid[data-count="5"] :deep(.player-tile:nth-child(-n+3)) {
  grid-column: span 2;
}
.tiles-grid[data-count="5"] :deep(.player-tile:nth-child(4)),
.tiles-grid[data-count="5"] :deep(.player-tile:nth-child(5)) {
  grid-column: span 3;
}

/* 6 players: 2×3 */
.tiles-grid[data-count="6"] {
  grid-template-columns: repeat(3, 1fr);
  grid-template-rows: 1fr 1fr;
}

/* ── End-game button ──────────────────────────────────────────── */
.end-game-fab {
  position: fixed;
  top: calc(env(safe-area-inset-top, 0px) + 12px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 10;
  height: 26px;
  padding: 0 14px;
  background: rgba(13, 13, 13, 0.75);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 13px;
  color: rgba(255, 255, 255, 0.5);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.15s ease;
}

.end-game-fab:hover {
  color: #fff;
  border-color: rgba(255, 255, 255, 0.35);
  background: rgba(13, 13, 13, 0.9);
}
</style>
