<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useGameStore } from '../stores/game'
import api from '../lib/api'
import VaultMark from '../components/VaultMark.vue'

const router = useRouter()
const game = useGameStore()

const PLAYER_COUNTS = [2, 3, 4, 5, 6]
const LIFE_PRESETS = [
  { label: 'Standard', value: 20 },
  { label: 'Commander', value: 40 },
  { label: 'Two-Headed Giant', value: 30 },
]

const playerCount = ref(null)
const lifeTotal = ref(40)
const customLife = ref('')
const usingCustomLife = ref(false)
const seats = ref([])
const decks = ref([])
const decksLoaded = ref(false)

onMounted(async () => {
  try {
    const { data } = await api.get('/decks')
    decks.value = data.data ?? data
  } catch {
    // Non-fatal: deck picker is optional
  } finally {
    decksLoaded.value = true
  }
})

watch(playerCount, (count) => {
  if (count === null) return
  const prev = seats.value.length
  if (count > prev) {
    for (let i = prev; i < count; i++) {
      seats.value.push({ name: `Player ${i + 1}`, deckId: null })
    }
  } else {
    seats.value.splice(count)
  }
})

function selectLifePreset(value) {
  lifeTotal.value = value
  customLife.value = ''
  usingCustomLife.value = false
}

function onCustomLifeInput(e) {
  const v = e.target.value
  customLife.value = v
  const n = parseInt(v, 10)
  if (!isNaN(n) && n > 0) {
    lifeTotal.value = n
    usingCustomLife.value = true
  }
}

const canStart = computed(() => playerCount.value !== null)

function startGame() {
  if (!canStart.value) return
  game.configure({
    count: playerCount.value,
    life: lifeTotal.value,
    seatConfig: seats.value.map((s) => ({
      name: s.name,
      deckId: s.deckId,
      life: lifeTotal.value,
    })),
  })
  router.push({ name: 'game' })
}

function goBack() {
  router.push({ name: 'collection' })
}
</script>

<template>
  <main class="setup-page">
    <header class="setup-header">
      <VaultMark />
      <button class="back" @click="goBack">← Back</button>
    </header>

    <section class="setup-content">
      <h1 class="title">New Game</h1>

      <!-- Player count -->
      <section class="setup-group">
        <h3 class="group-title">Players</h3>
        <div class="seg large">
          <button
            v-for="n in PLAYER_COUNTS"
            :key="n"
            :class="{ active: playerCount === n }"
            @click="playerCount = n"
          >{{ n }}</button>
        </div>
      </section>

      <!-- Starting life -->
      <section class="setup-group">
        <h3 class="group-title">Starting Life</h3>
        <div class="life-row">
          <div class="seg">
            <button
              v-for="preset in LIFE_PRESETS"
              :key="preset.value"
              :class="{ active: !usingCustomLife && lifeTotal === preset.value }"
              @click="selectLifePreset(preset.value)"
            >{{ preset.label }} ({{ preset.value }})</button>
          </div>
          <input
            class="life-input"
            type="number"
            min="1"
            placeholder="Custom"
            :value="usingCustomLife ? customLife : ''"
            :class="{ active: usingCustomLife }"
            @input="onCustomLifeInput"
          />
        </div>
      </section>

      <!-- Seat config -->
      <section v-if="playerCount" class="setup-group">
        <h3 class="group-title">Seats</h3>
        <div class="seats-list">
          <div v-for="(seat, i) in seats" :key="i" class="seat-row">
            <span class="seat-number">{{ i + 1 }}</span>
            <input
              v-model="seat.name"
              class="name-input"
              type="text"
              :placeholder="`Player ${i + 1}`"
            />
            <select
              v-if="decksLoaded && decks.length > 0"
              v-model="seat.deckId"
              class="deck-select"
            >
              <option :value="null">No deck</option>
              <option v-for="deck in decks" :key="deck.id" :value="deck.id">
                {{ deck.name }}
              </option>
            </select>
          </div>
        </div>
      </section>

      <button
        class="start-btn"
        :disabled="!canStart"
        @click="startGame"
      >Start Game</button>
    </section>
  </main>
</template>

<style scoped>
.setup-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  padding: 32px 48px 64px;
}

.setup-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 720px;
  margin: 0 auto 40px;
}

.back {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  height: 32px;
  padding: 0 14px;
  border-radius: var(--radius-sm);
  font-size: 12px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.back:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-1);
}

.setup-content {
  max-width: 720px;
  margin: 0 auto;
}

.title {
  font-family: var(--font-display), serif;
  font-size: 36px;
  font-weight: 400;
  letter-spacing: -0.02em;
  color: var(--amber);
  margin: 0 0 36px;
}

.setup-group {
  margin-bottom: 32px;
}

.group-title {
  font-family: var(--font-sans), sans-serif;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 10px;
  padding: 0 2px;
}

.seg {
  display: inline-flex;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: 999px;
  padding: 2px;
}
.seg.large button {
  padding: 6px 20px;
  height: 32px;
  font-size: 14px;
}
.seg button {
  padding: 4px 14px;
  height: 26px;
  font-size: 12px;
  font-weight: 500;
  color: var(--ink-50);
  background: transparent;
  border: 0;
  border-radius: 999px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.seg button:hover { color: var(--ink-100); }
.seg button.active {
  background: var(--amber);
  color: #1a1408;
  font-weight: 600;
}

.life-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.life-input {
  width: 90px;
  height: 30px;
  padding: 0 10px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  font-family: var(--font-sans);
  outline: none;
  transition: border-color 0.12s ease;
}
.life-input::placeholder { color: var(--ink-30); }
.life-input:focus { border-color: var(--ink-30); }
.life-input.active { border-color: var(--amber); }
/* hide browser spin buttons */
.life-input::-webkit-inner-spin-button,
.life-input::-webkit-outer-spin-button { -webkit-appearance: none; }
.life-input[type=number] { -moz-appearance: textfield; }

.seats-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.seat-row {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  padding: 10px 14px;
}

.seat-number {
  font-size: 11px;
  font-weight: 600;
  color: var(--ink-50);
  letter-spacing: 0.08em;
  width: 16px;
  flex-shrink: 0;
}

.name-input {
  flex: 1;
  min-width: 0;
  height: 28px;
  padding: 0 8px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  font-family: var(--font-sans);
  outline: none;
  transition: border-color 0.12s ease;
}
.name-input:focus { border-color: var(--ink-30); }

.deck-select {
  height: 28px;
  padding: 0 8px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-70);
  font-size: 12px;
  font-family: var(--font-sans);
  outline: none;
  cursor: pointer;
  max-width: 200px;
}
.deck-select:focus { border-color: var(--ink-30); }

.start-btn {
  margin-top: 8px;
  height: 44px;
  padding: 0 32px;
  background: var(--amber);
  border: 0;
  border-radius: var(--radius-sm);
  color: #1a1408;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.06em;
  cursor: pointer;
  transition: background 0.12s ease, opacity 0.12s ease;
}
.start-btn:hover:not(:disabled) { background: var(--amber-hi); }
.start-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}
</style>
