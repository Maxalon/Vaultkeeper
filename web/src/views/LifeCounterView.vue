<script setup>
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '../stores/settings'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'

const settings = useSettingsStore()

const phase = ref('setup') // 'setup' | 'game'
const playerCount = ref(4)
const startingLife = ref(40)
const players = ref([])

// Always reset to game setup on mount so navigating away and back
// never preserves stale game state.
onMounted(() => {
  phase.value = 'setup'
  players.value = []
})

function startGame() {
  players.value = Array.from({ length: playerCount.value }, (_, i) => ({
    id: i,
    name: `Player ${i + 1}`,
    life: startingLife.value,
  }))
  phase.value = 'game'
}

function adjustLife(idx, delta) {
  players.value[idx].life += delta
}

function resetGame() {
  phase.value = 'setup'
  players.value = []
}
</script>

<template>
  <div
    class="life-shell"
    :data-sidebar="settings.sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="life-counter"
      :sidebar-collapsed="settings.sidebarCollapsed"
      @toggle-sidebar="settings.toggleSidebarCollapsed()"
    />
    <LocationSidebar :collapsed="settings.sidebarCollapsed" />
    <main class="life-main">
      <div v-if="phase === 'setup'" class="setup-panel">
        <h2 class="setup-title">Game Setup</h2>

        <div class="setup-field">
          <label class="setup-label">Players</label>
          <div class="option-row">
            <button
              v-for="n in [2, 3, 4, 5, 6]"
              :key="n"
              class="option-btn"
              :class="{ active: playerCount === n }"
              @click="playerCount = n"
            >{{ n }}</button>
          </div>
        </div>

        <div class="setup-field">
          <label class="setup-label">Starting Life</label>
          <div class="option-row">
            <button
              v-for="n in [20, 30, 40]"
              :key="n"
              class="option-btn"
              :class="{ active: startingLife === n }"
              @click="startingLife = n"
            >{{ n }}</button>
          </div>
        </div>

        <button class="start-btn" @click="startGame">Start Game</button>
      </div>

      <div v-else class="game-panel">
        <div class="players-grid" :data-count="players.length">
          <div v-for="(p, i) in players" :key="p.id" class="player-card">
            <span class="player-name">{{ p.name }}</span>
            <div class="life-row">
              <button class="adj-btn" @click="adjustLife(i, -1)">−</button>
              <span class="life-total" :class="{ dead: p.life <= 0 }">{{ p.life }}</span>
              <button class="adj-btn" @click="adjustLife(i, +1)">+</button>
            </div>
            <div class="quick-row">
              <button class="adj-btn sm" @click="adjustLife(i, -5)">−5</button>
              <button class="adj-btn sm" @click="adjustLife(i, +5)">+5</button>
            </div>
          </div>
        </div>
        <button class="new-game-btn" @click="resetGame">New Game</button>
      </div>
    </main>
  </div>
</template>

<style scoped>
.life-shell {
  display: grid;
  grid-template-columns: var(--sidebar-width) 1fr;
  grid-template-rows: 56px 1fr;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  --brand-width: var(--sidebar-width);
  transition: grid-template-columns 200ms ease;
}
.life-shell[data-sidebar="collapsed"] {
  --sidebar-width: 0px;
  --brand-width: 96px;
}
.life-shell[data-sidebar="collapsed"] :deep(.location-sidebar) {
  display: none;
}
.life-shell :deep(.vk-topbar) {
  grid-column: 1 / -1;
  grid-row: 1;
}
.life-shell :deep(.location-sidebar) {
  grid-column: 1;
  grid-row: 2;
  min-height: 0;
}
.life-main {
  grid-column: 2;
  grid-row: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-0);
  overflow: auto;
  padding: 32px 24px;
}

/* ── Setup ───────────────────────────────────────────────────────── */
.setup-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
  max-width: 400px;
  width: 100%;
}

.setup-title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--ink-100);
  font-family: var(--font-sans), sans-serif;
}

.setup-field {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  width: 100%;
}

.setup-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-50);
  font-family: var(--font-sans), sans-serif;
}

.option-row {
  display: flex;
  gap: 8px;
}

.option-btn {
  min-width: 52px;
  height: 40px;
  padding: 0 12px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-70);
  font-size: 15px;
  font-weight: 500;
  font-family: var(--font-sans), sans-serif;
  cursor: pointer;
  transition: all 120ms ease;
}
.option-btn:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
}
.option-btn.active {
  background: var(--amber);
  border-color: var(--amber);
  color: #1a1408;
  font-weight: 700;
}

.start-btn {
  margin-top: 4px;
  width: 100%;
  height: 44px;
  background: var(--amber);
  color: #1a1408;
  border: 0;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-family: var(--font-sans), sans-serif;
  cursor: pointer;
  transition: background 120ms ease;
}
.start-btn:hover {
  background: color-mix(in oklab, var(--amber) 85%, white);
}

/* ── Game ────────────────────────────────────────────────────────── */
.game-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
  width: 100%;
  max-width: 900px;
}

.players-grid {
  display: grid;
  gap: 16px;
  width: 100%;
  grid-template-columns: repeat(2, 1fr);
}
.players-grid[data-count="2"] { grid-template-columns: repeat(2, 1fr); }
.players-grid[data-count="3"] { grid-template-columns: repeat(3, 1fr); }
.players-grid[data-count="4"] { grid-template-columns: repeat(2, 1fr); }
.players-grid[data-count="5"],
.players-grid[data-count="6"] { grid-template-columns: repeat(3, 1fr); }

.player-card {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 10px;
  padding: 20px 16px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.player-name {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-50);
  font-family: var(--font-sans), sans-serif;
}

.life-row {
  display: flex;
  align-items: center;
  gap: 16px;
}

.life-total {
  font-size: 52px;
  font-weight: 700;
  font-family: var(--font-mono), monospace;
  color: var(--ink-100);
  min-width: 90px;
  text-align: center;
  line-height: 1;
}
.life-total.dead {
  color: var(--red, #e05252);
}

.adj-btn {
  width: 40px;
  height: 40px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-70);
  font-size: 20px;
  font-weight: 400;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 120ms ease;
  padding: 0;
  line-height: 1;
}
.adj-btn:hover {
  color: var(--ink-100);
  background: var(--bg-3, var(--bg-2));
  border-color: var(--ink-30);
}

.adj-btn.sm {
  width: 52px;
  height: 30px;
  font-size: 12px;
  font-weight: 600;
  font-family: var(--font-sans), sans-serif;
}

.quick-row {
  display: flex;
  gap: 8px;
}

.new-game-btn {
  height: 36px;
  padding: 0 24px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-70);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  font-family: var(--font-sans), sans-serif;
  cursor: pointer;
  transition: all 120ms ease;
}
.new-game-btn:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
}
</style>
