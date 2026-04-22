<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import CardDetailBody from '../CardDetailBody.vue'
import ZoneSelector from './ZoneSelector.vue'
import CategoryInput from './CategoryInput.vue'
import QuantityStepper from './QuantityStepper.vue'
import PhysicalCopyDropdown from './PhysicalCopyDropdown.vue'

const deck = useDeckStore()

const entry = computed(() =>
  deck.entries.find((e) => e.id === deck.activeEntryId) || null,
)

const deckId = computed(() => deck.deck?.id)

const isIllegal = computed(() => {
  if (!entry.value) return false
  return !!deck.cardLevelIllegalitiesByScryfallId[entry.value.scryfall_id]
})

const isGc = computed(() =>
  deck.deck?.format === 'commander'
  && entry.value?.scryfall_card?.commander_game_changer,
)

function onClose() {
  deck.activeEntryId = null
}

function patch(fields) {
  if (!entry.value || !deckId.value) return
  return deck.updateEntry(deckId.value, entry.value.id, fields)
}

function onZoneChange(zone) {
  if (entry.value && !entry.value.is_commander) {
    deck.moveEntryZone(deckId.value, entry.value.id, zone)
  }
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
          :disabled="entry.is_commander"
          @change="onZoneChange"
        />
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
  background: var(--vk-surface, #1d1c1a);
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
  color: var(--vk-fg-dim, #a8a396);
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
  color: var(--vk-fg-dim, #a8a396);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.actions button {
  flex: 1;
  background: transparent;
  border: 1px solid var(--vk-border, #33312c);
  color: inherit;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-radius: 4px;
  font-size: 0.85rem;
}
.actions button:hover:not(:disabled) { background: var(--vk-surface-raised, #26241f); }
.actions button:disabled { opacity: 0.4; cursor: not-allowed; }
.actions .danger {
  background: #7c3226;
  border-color: #8e3c31;
  color: #f5eadf;
}
.actions .danger:hover { background: #8e3c31; }
</style>
