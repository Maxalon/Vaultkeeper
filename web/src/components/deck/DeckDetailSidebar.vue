<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useDeckEntryActions } from '../../composables/useDeckEntryActions'
import CardDetailBody from '../CardDetailBody.vue'
import PrintingPickerModal from '../PrintingPickerModal.vue'
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

const isBound = computed(() => entry.value?.physical_copy_id != null)
const oracleId = computed(() => entry.value?.scryfall_card?.oracle_id || null)

function close() {
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

/**
 * Find the (scryfall_id, zone)-matching wanted sibling for the
 * current entry, if any. Used by +1/-1 to route quantity changes
 * to the wanted side first — bound CE-backed quantity only moves
 * via the explicit inline-picker "I bought it" path.
 */
function findWantedSibling(e) {
  if (!e) return null
  return deck.entries.find(
    (r) => r.id !== e.id
        && r.scryfall_id === e.scryfall_id
        && r.zone === e.zone
        && r.physical_copy_id == null
        && r.wanted != null,
  ) || null
}

function onIncrement() {
  if (!entry.value || !deckId.value) return
  const e = entry.value
  // Unbound entry (wanted-only or unset): just bump it.
  if (e.physical_copy_id == null) {
    deck.updateEntry(deckId.value, e.id, { quantity: e.quantity + 1 })
    return
  }
  // Bound entry: bump the wanted sibling, or mint one when none exists.
  const sibling = findWantedSibling(e)
  if (sibling) {
    deck.updateEntry(deckId.value, sibling.id, { quantity: sibling.quantity + 1 })
  } else {
    deck.growWanted(deckId.value, e.scryfall_id, e.zone)
  }
}

function onDecrement() {
  if (!entry.value || !deckId.value) return
  const e = entry.value
  // Unbound entry: −1 hits this row directly. At qty=1, removing
  // the row deletes it (no observer side-effects since it's wanted-only).
  if (e.physical_copy_id == null) {
    if (e.quantity <= 1) {
      deck.removeEntry(deckId.value, e.id)
    } else {
      deck.updateEntry(deckId.value, e.id, { quantity: e.quantity - 1 })
    }
    return
  }
  // Bound entry with a wanted sibling: peel the sibling first, deleting
  // it at qty=1.
  const sibling = findWantedSibling(e)
  if (sibling) {
    if (sibling.quantity <= 1) {
      deck.removeEntry(deckId.value, sibling.id)
    } else {
      deck.updateEntry(deckId.value, sibling.id, { quantity: sibling.quantity - 1 })
    }
    return
  }
  // Purely-bound entry, no wanted sibling: existing observer-driven
  // path (queues the freed copy to the review/pending bucket).
  if (e.quantity > 1) {
    deck.updateEntry(deckId.value, e.id, { quantity: e.quantity - 1 })
  }
}

function runAction(action) {
  action.run().catch(() => { /* store-level toast */ })
}

const printingPickerOpen = ref(false)
function openPrintingPicker() {
  if (!oracleId.value || isBound.value) return
  printingPickerOpen.value = true
}
function onPickPrinting(scryfallId) {
  if (!entry.value || !deckId.value || scryfallId === entry.value.scryfall_id) return
  patch({ scryfall_id: scryfallId })
}
</script>

<template>
  <aside v-if="entry" class="vk-detail vk-detail--deck">
    <header class="vk-detail-header">
      <button class="close" type="button" @click="close" title="Close" aria-label="Close">✕</button>
    </header>

    <div class="vk-detail-body">
      <button
        v-if="!isBound && oracleId"
        type="button"
        class="choose-printing-btn"
        @click="openPrintingPicker"
      >Choose printing…</button>

      <div class="card-wrap" :class="{ 'illegal-glow': isIllegal }">
        <CardDetailBody :card="entry.scryfall_card" />
        <span v-if="isGc" class="gc-badge">GC</span>
      </div>

      <section class="vk-detail-section">
        <h4>Zone</h4>
        <ZoneSelector
          :value="entry.zone"
          :disabled="isRoleLocked"
          @change="onZoneChange"
        />
      </section>

      <section v-if="actions.length" class="vk-detail-section">
        <h4>{{ entry.is_commander ? 'Commander' : entry.is_signature_spell ? 'Signature spell' : 'Actions' }}</h4>
        <div class="role-actions">
          <button
            v-for="a in actions"
            :key="a.id"
            type="button"
            class="role-btn"
            :class="{
              'role-btn--primary': a.kind === 'primary',
              'role-btn--danger':  a.kind === 'danger',
            }"
            @click="runAction(a)"
          >
            <span>{{ a.label }}</span>
            <span v-if="a.hint" class="role-hint">{{ a.hint }}</span>
          </button>
        </div>
      </section>

      <section class="vk-detail-section">
        <h4>Category</h4>
        <CategoryInput
          :value="entry.category || ''"
          :suggestions="deck.categoriesInDeck"
          @commit="patch({ category: $event || null })"
        />
      </section>

      <section class="vk-detail-section">
        <h4>Quantity</h4>
        <QuantityStepper
          :value="entry.quantity"
          @dec="onDecrement"
          @inc="onIncrement"
        />
      </section>

      <section v-if="!isBound" class="vk-detail-section">
        <h4>Physical copy</h4>
        <PhysicalCopyDropdown
          :scryfall-id="entry.scryfall_id"
          :value="entry.physical_copy_id"
          @select="patch({ physical_copy_id: $event })"
        />
      </section>
    </div>

    <PrintingPickerModal
      v-model:open="printingPickerOpen"
      :oracle-id="oracleId"
      :card-name="entry.scryfall_card?.name || ''"
      :selected-printing-id="entry.scryfall_id"
      @select="onPickPrinting"
    />
  </aside>
</template>

<style scoped>
.vk-detail {
  width: var(--detail-width, 360px);
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

.vk-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.choose-printing-btn {
  display: block;
  width: 100%;
  background: var(--bg-0);
  border: 1px solid var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  padding: 8px 6px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  margin-bottom: 12px;
  transition: background 0.1s ease, color 0.1s ease;
}
.choose-printing-btn:hover {
  background: var(--amber-lo);
  color: #1a1408;
}

.card-wrap {
  position: relative;
}
.gc-badge {
  position: absolute;
  bottom: 10px;
  right: 10px;
  background: linear-gradient(135deg, #f0c35c, #c99d3d);
  color: #0f0e0b;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
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

.role-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.role-btn {
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  color: var(--ink-100);
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 13px;
  text-align: center;
  font: inherit;
  transition: background 0.1s ease, border-color 0.1s ease, color 0.1s ease;
}
.role-btn:hover { background: var(--bg-2); border-color: var(--amber-lo); }
.role-btn--primary {
  border-color: var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
}
.role-btn--primary:hover {
  background: var(--amber-lo);
  color: #1a1408;
}
.role-btn--danger {
  color: var(--cond-hp, #d97757);
}
.role-btn--danger:hover {
  background: rgba(217, 119, 87, 0.08);
  border-color: var(--cond-hp, #d97757);
}
.role-btn .role-hint {
  display: block;
  font-size: 11px;
  color: var(--ink-50);
  margin-top: 2px;
  font-weight: 400;
}
</style>
