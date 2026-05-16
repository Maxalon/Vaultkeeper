<script setup>
import { computed, ref, watch } from 'vue'
import { useCollectionStore } from '../stores/collection'
import { confirm as confirmDialog } from '../composables/useConfirm'
import CardDetailBody from './CardDetailBody.vue'
import PriceLine from './PriceLine.vue'

const collection = useCollectionStore()

const entry = computed(() => collection.activeEntry)
const card = computed(() => entry.value?.card || null)

const saveError = ref(null)
const quickMovePending = ref(false)

// Clear any stale error when the active entry changes.
watch(() => collection.activeEntryId, () => { saveError.value = null })

async function patch(payload) {
  if (!entry.value) return
  saveError.value = null
  try {
    await collection.updateEntry(entry.value.id, payload)
  } catch (err) {
    saveError.value = err?.response?.data?.message || 'Save failed. Please try again.'
  }
}

function onConditionChange(e) { patch({ condition: e.target.value }) }
function onLocationChange(e) {
  const val = e.target.value
  patch({ location_id: val === '' ? null : Number(val) })
}
function onQuantityChange(e) {
  const q = Math.max(1, Math.min(9999, Number(e.target.value) || 1))
  patch({ quantity: q })
}

const finishValue = computed(() => {
  if (!entry.value) return 'nonfoil'
  if (entry.value.is_etched) return 'etched'
  if (entry.value.foil) return 'foil'
  return 'nonfoil'
})

function onFinishChange(e) {
  const next = e.target.value
  // Mutually exclusive: setting one resets the other. Backend enforces
  // the same invariant; keeping the request shape explicit makes the
  // optimistic merge in collection.updateEntry land consistently.
  if (next === 'etched') patch({ foil: false, is_etched: true })
  else if (next === 'foil') patch({ foil: true, is_etched: false })
  else patch({ foil: false, is_etched: false })
}

async function onRemove() {
  if (!entry.value) return
  const name = entry.value.card?.name || 'this card'
  const ok = await confirmDialog({
    title: 'Remove from collection',
    message: `Remove ${name} from your collection? This cannot be undone.`,
    confirmText: 'Remove',
    cancelText: 'Cancel',
    destructive: true,
  })
  if (!ok) return
  await collection.deleteEntry(entry.value.id)
}

async function quickMove(locationId) {
  if (!entry.value || quickMovePending.value) return
  quickMovePending.value = true
  try {
    await patch({ location_id: locationId })
  } finally {
    quickMovePending.value = false
  }
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

      <PriceLine
        :prices="card.prices"
        :foil="!!entry.foil"
        :is-etched="!!entry.is_etched"
      />

      <section v-if="realLocations.length" class="vk-detail-section">
        <h4>Move to location</h4>
        <div class="quick-move-grid">
          <button
            v-for="loc in realLocations"
            :key="loc.id"
            type="button"
            class="quick-move-btn"
            :class="{ active: entry.location_id === loc.id }"
            :disabled="quickMovePending"
            @click="quickMove(loc.id)"
          >{{ loc.name }}</button>
        </div>
      </section>

      <section class="vk-detail-section">
        <h4>Your Copies</h4>

        <div v-if="saveError" class="vk-save-error" role="alert">
          {{ saveError }}
        </div>

        <div class="vk-field-row">
          <label class="vk-field">
            <span class="vk-field-label">Condition</span>
            <select name="condition" :value="entry.condition" @change="onConditionChange" class="vk-field-input">
              <option>NM</option>
              <option>LP</option>
              <option>MP</option>
              <option>HP</option>
              <option>DMG</option>
            </select>
          </label>
          <label class="vk-field">
            <span class="vk-field-label">Location</span>
            <select name="location" :value="entry.location_id ?? ''" @change="onLocationChange" class="vk-field-input">
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
              name="quantity"
              autocomplete="off"
              min="1"
              max="9999"
              :value="entry.quantity"
              class="vk-field-input"
              @change="onQuantityChange"
            />
          </label>
          <label class="vk-field">
            <span class="vk-field-label">Finish</span>
            <select name="finish" :value="finishValue" @change="onFinishChange" class="vk-field-input">
              <option value="nonfoil">Nonfoil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>
          </label>
        </div>

        <div class="vk-remove-row">
          <button type="button" class="vk-remove-btn" @click="onRemove">
            Remove from collection
          </button>
        </div>
      </section>

      <section v-if="entry.source_deck" class="source-deck">
        <span class="source-deck-label">From</span>
        <span class="source-deck-name">
          {{ entry.source_deck.deck_name || 'a deleted deck' }}
          <span v-if="entry.source_deck.deleted" class="source-deck-deleted">(deleted)</span>
        </span>
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

.vk-save-error {
  margin-bottom: 10px;
  padding: 8px 10px;
  background: rgba(209, 107, 107, 0.1);
  border: 1px solid rgba(209, 107, 107, 0.35);
  border-radius: var(--radius-sm);
  color: #d97757;
  font-size: 12px;
  line-height: 1.4;
}

.quick-move-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.quick-move-btn {
  padding: 5px 10px;
  font-size: 11px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.1s ease, border-color 0.1s ease, color 0.1s ease;
  white-space: nowrap;
}
.quick-move-btn:hover:not(:disabled) {
  border-color: var(--amber-lo);
  color: var(--amber);
}
.quick-move-btn.active {
  background: var(--amber-lo, #3a2f0f);
  border-color: var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
}
.quick-move-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.vk-remove-row {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}
.vk-remove-btn {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--cond-hp, #d97757);
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.1s ease, border-color 0.1s ease;
}
.vk-remove-btn:hover {
  background: rgba(217, 119, 87, 0.08);
  border-color: var(--cond-hp, #d97757);
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

.source-deck {
  margin-top: 12px;
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 8px 10px;
  background: var(--bg-2, #1d1c1a);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  font-size: 12px;
}
.source-deck-label {
  color: var(--ink-50);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
}
.source-deck-name { color: var(--ink-100); }
.source-deck-deleted {
  color: var(--ink-50);
  margin-left: 4px;
  font-style: italic;
}
</style>
