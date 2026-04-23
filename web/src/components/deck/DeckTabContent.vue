<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import DeckInfoPanel from './DeckInfoPanel.vue'
import CommanderZone from './CommanderZone.vue'
import DeckFilterBar from './DeckFilterBar.vue'
import DeckGrid from './DeckGrid.vue'
import ZoneDivider from './ZoneDivider.vue'
import LocationModal from '../LocationModal.vue'
import { downloadDeck } from '../../utils/deckExport'

defineProps({
  zone: { type: String, default: null },  // when set (side|maybe), render only that zone
})

const deck = useDeckStore()

const editOpen = ref(false)
const editLocation = computed(() =>
  deck.deck ? { ...deck.deck, kind: 'deck' } : null,
)

function onEdit() {
  if (deck.deck) editOpen.value = true
}
function onExport(format) {
  if (deck.deck) downloadDeck(format, deck.deck, deck.entries)
}
async function onEditClosed() {
  editOpen.value = false
  const id = deck.deck?.id
  if (!id) return
  await Promise.all([
    deck.loadDeck(id),
    deck.loadEntries(id),
    deck.loadIllegalities(id),
  ])
}
</script>

<template>
  <div v-if="deck.deck" class="deck-tab-content">
    <!-- Zone-scoped undocked view -->
    <template v-if="zone">
      <DeckFilterBar />
      <DeckGrid :zone="zone" />
    </template>

    <!-- Full deck layout -->
    <template v-else>
      <div class="deck-top-row">
        <DeckInfoPanel class="info-panel" @edit="onEdit" @export="onExport" />
        <CommanderZone class="commander-zone" />
      </div>

      <DeckFilterBar />

      <DeckGrid zone="main" />

      <template v-if="!deck.sideUndocked">
        <ZoneDivider zone="side" />
        <DeckGrid zone="side" />
      </template>

      <template v-if="!deck.maybeUndocked">
        <ZoneDivider zone="maybe" />
        <DeckGrid zone="maybe" />
      </template>
    </template>
  </div>
  <LocationModal
    v-if="editOpen && editLocation"
    :location="editLocation"
    @close="onEditClosed"
  />
</template>

<style scoped>
.deck-tab-content {
  height: 100%;
  overflow-y: auto;
}
.deck-top-row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}
.info-panel {
  flex: 1 1 auto;
  border-bottom: none;
}
.commander-zone {
  flex: 0 0 auto;
}
</style>
