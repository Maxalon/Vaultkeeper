<script setup>
import { ref } from 'vue'
import { useCollectionStore } from '../stores/collection'
import NewLocationModal from './NewLocationModal.vue'

const collection = useCollectionStore()
const modalOpen = ref(false)

function rowKey(loc) {
  return loc.id ?? 'unassigned'
}

function activeKey() {
  if (collection.activeLocationId === 'unassigned') return 'unassigned'
  return collection.activeLocationId
}

function isActive(loc) {
  return rowKey(loc) === activeKey()
}

function activate(loc) {
  collection.setActiveLocation(loc.id ?? 'unassigned')
}
</script>

<template>
  <aside class="sidebar">
    <header class="brand">
      <h1 class="display">VAULTKEEPER</h1>
    </header>

    <div class="mode-toggle" role="group" aria-label="Display mode">
      <button
        type="button"
        class="mode-btn"
        :class="{ active: collection.displayMode === 'A' }"
        @click="collection.setDisplayMode('A')"
      >A</button>
      <button
        type="button"
        class="mode-btn"
        :class="{ active: collection.displayMode === 'B' }"
        @click="collection.setDisplayMode('B')"
      >B</button>
    </div>

    <nav class="locations">
      <button
        v-for="loc in collection.locations"
        :key="rowKey(loc)"
        type="button"
        class="loc-row"
        :class="{ active: isActive(loc), virtual: loc.type === 'virtual' }"
        @click="activate(loc)"
      >
        <span class="icon" aria-hidden="true">
          <!-- drawer icon -->
          <svg v-if="loc.type === 'drawer'" viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/>
            <line x1="2.5" y1="9" x2="17.5" y2="9"/>
            <circle cx="10" cy="6" r="0.7" fill="currentColor"/>
            <circle cx="10" cy="13" r="0.7" fill="currentColor"/>
          </svg>
          <!-- binder icon -->
          <svg v-else-if="loc.type === 'binder'" viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="3.5" y="2.5" width="13" height="15" rx="1"/>
            <line x1="6" y1="2.5" x2="6" y2="17.5"/>
            <line x1="8" y1="6" x2="14" y2="6"/>
            <line x1="8" y1="10" x2="14" y2="10"/>
            <line x1="8" y1="14" x2="14" y2="14"/>
          </svg>
          <!-- virtual icon (asterisk-ish) -->
          <svg v-else viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="10" cy="10" r="6.5"/>
            <line x1="10" y1="6.5" x2="10" y2="13.5"/>
            <line x1="6.5" y1="10" x2="13.5" y2="10"/>
          </svg>
        </span>
        <span class="name">{{ loc.name }}</span>
        <span class="count">{{ loc.card_count }}</span>
      </button>
    </nav>

    <footer>
      <button type="button" class="new-btn" @click="modalOpen = true">＋ New Location</button>
    </footer>

    <NewLocationModal v-if="modalOpen" @close="modalOpen = false" />
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  background: var(--bg-1);
  border-right: 1px solid var(--border);
  height: 100vh;
  overflow: hidden;
}
.brand {
  padding: 22px 18px 18px;
  border-bottom: 1px solid var(--border);
}
.brand h1 {
  font-size: 20px;
  letter-spacing: 0.18em;
  color: var(--gold);
  text-align: center;
}

.mode-toggle {
  display: flex;
  margin: 12px 18px 14px;
  border: 1px solid var(--border);
  border-radius: 4px;
  overflow: hidden;
  background: var(--bg-0);
}
.mode-btn {
  flex: 1;
  background: transparent;
  border: none;
  border-radius: 0;
  padding: 6px 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--text-dim);
  font-family: var(--font-display);
  transition: background 120ms ease, color 120ms ease;
}
.mode-btn:hover {
  background: var(--bg-2);
  color: var(--text);
  border-color: transparent;
}
.mode-btn.active {
  background: var(--gold);
  color: var(--bg-0);
}

.locations {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;
  display: flex;
  flex-direction: column;
}
.loc-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  background: transparent;
  border: none;
  border-left: 3px solid transparent;
  border-radius: 0;
  padding: 9px 14px 9px 11px;
  color: var(--text-dim);
  text-align: left;
  font-size: 13px;
  cursor: pointer;
  transition: background 100ms ease, color 100ms ease;
}
.loc-row:hover {
  background: var(--bg-2);
  color: var(--text);
}
.loc-row.active {
  background: var(--bg-2);
  color: var(--text);
  border-left-color: var(--gold);
}
.loc-row.virtual {
  font-style: italic;
  color: var(--text-faint);
}
.loc-row.virtual.active {
  color: var(--text);
}
.icon {
  display: inline-flex;
  width: 16px;
  flex-shrink: 0;
}
.name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.count {
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  background: var(--bg-0);
  color: var(--text-dim);
  padding: 1px 6px;
  border-radius: 8px;
  min-width: 22px;
  text-align: center;
}
.loc-row.active .count {
  background: var(--gold);
  color: var(--bg-0);
  font-weight: 700;
}
footer {
  padding: 12px 14px 16px;
  border-top: 1px solid var(--border);
}
.new-btn {
  width: 100%;
  background: transparent;
  border: 1px dashed var(--gold-dim);
  color: var(--gold);
  font-size: 12px;
  padding: 9px;
}
.new-btn:hover {
  background: rgba(201, 162, 39, 0.07);
  border-color: var(--gold);
}
</style>
