<script setup>
import { computed } from 'vue'
import { useCollectionStore } from '../stores/collection'
import CardDetailBody from './CardDetailBody.vue'

const collection = useCollectionStore()

const entry = computed(() => collection.activeEntry)
const card = computed(() => entry.value?.card || null)

async function patch(payload) {
  if (!entry.value) return
  await collection.updateEntry(entry.value.id, payload)
}

function onConditionChange(e) { patch({ condition: e.target.value }) }
function onLocationChange(e) {
  const val = e.target.value
  patch({ location_id: val === '' ? null : Number(val) })
}
function onFoilChange(e) { patch({ foil: e.target.checked }) }
function onQuantityChange(e) {
  const q = Math.max(1, Math.min(9999, Number(e.target.value) || 1))
  patch({ quantity: q })
}

function close() { collection.closeActiveEntry() }

const realLocations = computed(() => collection.locations)
</script>

<template>
  <aside v-if="entry" class="vk-detail">
    <header class="vk-detail-header">
      <button class="close" @click="close" title="Close" aria-label="Close">✕</button>
    </header>

    <div v-if="collection.detailLoading" class="loading">Loading…</div>

    <div v-else-if="card" class="vk-detail-body">
      <CardDetailBody :card="card" />

      <section class="vk-detail-section">
        <h4>Your Copies</h4>

        <div class="vk-field-row">
          <label class="vk-field">
            <span class="vk-field-label">Condition</span>
            <select :value="entry.condition" @change="onConditionChange" class="vk-field-input">
              <option>NM</option>
              <option>LP</option>
              <option>MP</option>
              <option>HP</option>
              <option>DMG</option>
            </select>
          </label>
          <label class="vk-field">
            <span class="vk-field-label">Location</span>
            <select :value="entry.location_id ?? ''" @change="onLocationChange" class="vk-field-input">
              <option value="">Unassigned</option>
              <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
            </select>
          </label>
        </div>

        <div class="vk-field-row">
          <label class="vk-field">
            <span class="vk-field-label">Quantity</span>
            <input
              type="number"
              min="1"
              max="9999"
              :value="entry.quantity"
              class="vk-field-input"
              @change="onQuantityChange"
            />
          </label>
          <label class="vk-field foil-field">
            <span class="vk-field-label">Foil</span>
            <span class="foil-toggle">
              <input type="checkbox" :checked="entry.foil" @change="onFoilChange" />
              <span>{{ entry.foil ? 'Yes' : 'No' }}</span>
            </span>
          </label>
        </div>
      </section>

      <section v-if="entry.wanted_by_decks?.length" class="wanted">
        <span class="warn">⚠</span>
        <div>
          <div class="wanted-title">Wanted by decks</div>
          <ul>
            <li v-for="(name, i) in entry.wanted_by_decks" :key="i">{{ name }}</li>
          </ul>
        </div>
      </section>
    </div>
  </aside>
</template>

<style scoped>
.vk-detail {
  width: var(--detail-width);
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--bg-1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}

.vk-detail-header {
  padding: 10px 12px 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-shrink: 0;
}
.close {
  background: transparent;
  border: 0;
  color: var(--ink-50);
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  padding: 0;
  transition: background 0.1s ease, color 0.1s ease;
}
.close:hover { background: var(--bg-2); color: var(--ink-100); }

.loading {
  color: var(--ink-50);
  text-align: center;
  padding: 60px 0;
  font-style: italic;
}

.vk-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.vk-detail-section { margin-top: 20px; }
.vk-detail-section h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 10px;
  font-family: var(--font-sans), sans-serif;
}

.vk-field { display: block; margin-bottom: 0; flex: 1; }
.vk-field-label {
  display: block;
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin-bottom: 4px;
  font-weight: 600;
}
.vk-field-input {
  width: 100%;
  height: 32px;
  padding: 0 10px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  font-family: inherit;
  outline: 0;
  appearance: none;
  -webkit-appearance: none;
}
.vk-field-input:focus { border-color: var(--amber-lo); }
select.vk-field-input {
  background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236d6d78' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  padding-right: 26px;
}
.vk-field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 10px;
}
.foil-field .foil-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
  padding: 0 10px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  font-size: 13px;
  color: var(--ink-100);
  cursor: pointer;
}
.foil-field input[type="checkbox"] {
  width: auto;
  margin: 0;
  accent-color: var(--amber);
}

.wanted {
  margin-top: 16px;
  display: flex;
  gap: 10px;
  background: rgba(251, 146, 60, 0.08);
  border: 1px solid rgba(251, 146, 60, 0.3);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}
.warn { font-size: 18px; color: var(--cond-hp); }
.wanted-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ink-50);
  margin-bottom: 4px;
}
.wanted ul {
  margin: 0;
  padding-left: 16px;
  font-size: 12px;
  color: var(--ink-100);
}
</style>
