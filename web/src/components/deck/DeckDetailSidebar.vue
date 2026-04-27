<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useDeckEntryActions } from '../../composables/useDeckEntryActions'
import CardDetailBody from '../CardDetailBody.vue'
import ZoneSelector from './ZoneSelector.vue'
import CategoryInput from './CategoryInput.vue'
import QuantityStepper from './QuantityStepper.vue'
import PhysicalCopyDropdown from './PhysicalCopyDropdown.vue'

const deck = useDeckStore()

const entry = computed(() =>
  deck.entries.find((e) => e.id === deck.activeEntryId) || null,
)
const { actions } = useDeckEntryActions(entry)

const deckId = computed(() => deck.deck?.id)

const isIllegal = computed(() => {
  if (!entry.value) return false
  return !!deck.cardLevelIllegalitiesByScryfallId[entry.value.scryfall_id]
})

const isGc = computed(() =>
  deck.deck?.format === 'commander'
  && entry.value?.scryfall_card?.commander_game_changer,
)

const isRoleLocked = computed(
  () => !!(entry.value?.is_commander || entry.value?.is_signature_spell),
)

function onClose() {
  deck.activeEntryId = null
}

function patch(fields) {
  if (!entry.value || !deckId.value) return
  return deck.updateEntry(deckId.value, entry.value.id, fields)
}

function onZoneChange(zone) {
  if (entry.value && !isRoleLocked.value) {
    deck.moveEntryZone(deckId.value, entry.value.id, zone)
  }
}

function runAction(action) {
  action.run().catch(() => { /* store-level toast */ })
}

function onRemove() {
  if (!entry.value) return
  deck.removeEntry(deckId.value, entry.value.id)
}
</script>

<template>
  <aside v-if="entry" class="deck-detail-sidebar">
    <header class="detail-header">
      <button type="button" class="close-btn" @click="onClose">×</button>
    </header>

    <div class="card-wrap" :class="{ 'illegal-glow': isIllegal }">
      <CardDetailBody :card="entry.scryfall_card" />
      <span v-if="isGc" class="gc-badge">GC</span>
    </div>

    <section class="deck-context">
      <div class="field">
        <label>Zone</label>
        <ZoneSelector
          :value="entry.zone"
          :disabled="isRoleLocked"
          @change="onZoneChange"
        />
      </div>

      <div v-if="actions.length" class="field">
        <label>{{ entry.is_commander ? 'Commander' : entry.is_signature_spell ? 'Signature spell' : 'Role' }}</label>
        <div class="role-actions">
          <button
            v-for="a in actions"
            :key="a.id"
            type="button"
            class="role-btn"
            :class="{ 'role-btn--primary': a.kind === 'primary' }"
            @click="runAction(a)"
          >
            <span>{{ a.label }}</span>
            <span v-if="a.hint" class="role-hint">{{ a.hint }}</span>
          </button>
        </div>
      </div>

      <div class="field">
        <label>Category</label>
        <CategoryInput
          :value="entry.category || ''"
          :suggestions="deck.categoriesInDeck"
          @commit="patch({ category: $event || null })"
        />
      </div>

      <div class="field">
        <label>Quantity</label>
        <QuantityStepper
          :value="entry.quantity"
          @dec="patch({ quantity: Math.max(1, entry.quantity - 1) })"
          @inc="patch({ quantity: entry.quantity + 1 })"
        />
      </div>

      <div class="field">
        <label>Physical copy</label>
        <PhysicalCopyDropdown
          :scryfall-id="entry.scryfall_id"
          :value="entry.physical_copy_id"
          @select="patch({ physical_copy_id: $event })"
        />
      </div>

      <div class="actions">
        <button type="button" disabled title="Foil toggle lands after deck_entries.foil migration">Foil</button>
        <button type="button" class="danger" @click="onRemove">Remove from deck</button>
      </div>
    </section>
  </aside>
</template>

<style scoped>
.deck-detail-sidebar {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--bg-2, #1d1c1a);
  overflow-y: auto;
}
.detail-header {
  display: flex;
  justify-content: flex-end;
  padding: 0.5rem;
}
.close-btn {
  background: transparent;
  border: none;
  color: var(--ink-70, #a8a396);
  font-size: 1.4rem;
  cursor: pointer;
}
.card-wrap {
  padding: 0.5rem 1rem;
  position: relative;
  border-radius: 8px;
}
.gc-badge {
  position: absolute;
  bottom: 10px;
  right: 20px;
  background: linear-gradient(135deg, #f0c35c, #c99d3d);
  color: #0f0e0b;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
}
.deck-context {
  padding: 0 1rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
}
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label {
  font-size: 0.72rem;
  color: var(--ink-70, #a8a396);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.actions button {
  flex: 1;
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-radius: 4px;
  font-size: 0.85rem;
}
.actions button:hover:not(:disabled) { background: var(--bg-2, #26241f); }
.actions button:disabled { opacity: 0.4; cursor: not-allowed; }
.actions .danger {
  background: #7c3226;
  border-color: #8e3c31;
  color: #f5eadf;
}
.actions .danger:hover { background: #8e3c31; }
.role-actions {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.role-btn {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 0.5rem 0.75rem;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85rem;
  text-align: center;
  font: inherit;
}
.role-btn:hover { background: var(--bg-2, #26241f); }
.role-btn--primary {
  border-color: #8a6d2e;
  color: var(--amber, #c9a552);
}
.role-btn--primary:hover {
  background: #2a2516;
  border-color: #a18030;
}
.role-btn .role-hint {
  display: block;
  font-size: 0.7rem;
  color: var(--ink-70, #a8a396);
  margin-top: 2px;
  font-weight: 400;
}
</style>
