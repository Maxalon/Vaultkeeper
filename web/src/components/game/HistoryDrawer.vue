<script setup>
import { computed } from 'vue'
import { useGameStore } from '../../stores/game'

defineProps({
  open: { type: Boolean, required: true },
})

const emit = defineEmits(['close'])

const game = useGameStore()

// Show newest entries first.
const entries = computed(() => [...game.history].reverse())

const COUNTER_LABEL = {
  life: 'Life',
  poison: 'Poison',
  commanderDamage: 'Commander Damage',
  dice: 'Dice',
}

function formatDelta(entry) {
  if (entry.counterType === 'dice') return `→ ${entry.delta}`
  return entry.delta >= 0 ? `+${entry.delta}` : `${entry.delta}`
}

function formatTime(ts) {
  return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>

<template>
  <Transition name="drawer">
    <div v-if="open" class="history-backdrop" @click.self="emit('close')">
      <div class="history-drawer" role="dialog" aria-label="Game history log">
        <div class="drawer-header">
          <span class="drawer-title">History</span>
          <button class="close-btn" aria-label="Close history" @click="emit('close')">✕</button>
        </div>
        <div class="drawer-body">
          <p v-if="entries.length === 0" class="empty-msg">No events yet.</p>
          <ul v-else class="entry-list">
            <li
              v-for="(entry, i) in entries"
              :key="i"
              class="entry-row"
              :class="{ undone: entry.undone }"
            >
              <span class="entry-time">{{ formatTime(entry.timestamp) }}</span>
              <span class="entry-player">{{ entry.playerName }}</span>
              <span class="entry-type">{{ COUNTER_LABEL[entry.counterType] ?? entry.counterType }}</span>
              <span class="entry-delta" :class="entry.delta >= 0 ? 'pos' : 'neg'">{{ formatDelta(entry) }}</span>
              <span v-if="entry.undone" class="undone-badge">(undone)</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.history-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  z-index: 50;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.history-drawer {
  width: 100%;
  max-width: 480px;
  max-height: 70vh;
  background: #1a1a1a;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px 16px 0 0;
  display: flex;
  flex-direction: column;
  padding-bottom: env(safe-area-inset-bottom, 0px);
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px 12px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.07);
  flex-shrink: 0;
}

.drawer-title {
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.5);
}

.close-btn {
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.4);
  font-size: 14px;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}

.close-btn:hover {
  color: #fff;
}

.drawer-body {
  overflow-y: auto;
  flex: 1;
  padding: 8px 0;
}

.empty-msg {
  text-align: center;
  color: rgba(255, 255, 255, 0.3);
  font-size: 13px;
  padding: 24px 0;
  margin: 0;
}

.entry-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.entry-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  font-size: 13px;
}

.entry-row.undone {
  opacity: 0.4;
}

.entry-time {
  color: rgba(255, 255, 255, 0.3);
  font-size: 11px;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}

.entry-player {
  font-weight: 600;
  color: rgba(255, 255, 255, 0.85);
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.entry-type {
  color: rgba(255, 255, 255, 0.4);
  font-size: 11px;
  flex-shrink: 0;
}

.entry-delta {
  font-weight: 700;
  flex-shrink: 0;
  min-width: 36px;
  text-align: right;
}

.entry-delta.pos { color: #5eb870; }
.entry-delta.neg { color: #e05252; }

.undone-badge {
  color: rgba(255, 255, 255, 0.3);
  font-size: 11px;
  flex-shrink: 0;
}

/* ── Slide-up transition ──────────────────────────────────────── */
.drawer-enter-active,
.drawer-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-enter-active .history-drawer,
.drawer-leave-active .history-drawer {
  transition: transform 0.2s ease;
}
.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
}
.drawer-enter-from .history-drawer,
.drawer-leave-to .history-drawer {
  transform: translateY(100%);
}
</style>
