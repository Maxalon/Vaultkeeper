<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import DeckInfoPanel from './DeckInfoPanel.vue'
import DeckFilterBar from './DeckFilterBar.vue'
import DeckGrid from './DeckGrid.vue'
import ZoneDivider from './ZoneDivider.vue'
import LocationModal from '../LocationModal.vue'
import { copyDeckToClipboard, downloadDeck } from '../../utils/deckExport'
import { useToast } from '../../composables/useToast'

defineProps({
  zone: { type: String, default: null },  // when set (side|maybe), render only that zone
})

const deck = useDeckStore()
const toast = useToast()

const editOpen = ref(false)
const editLocation = computed(() =>
  deck.deck ? { ...deck.deck, kind: 'deck' } : null,
)

function onEdit() {
  if (deck.deck) editOpen.value = true
}
async function onExport({ action, format }) {
  if (!deck.deck) return
  if (action === 'copy') {
    try {
      await copyDeckToClipboard(format, deck.deck, deck.entries)
      toast.success('Decklist copied to clipboard')
    } catch {
      toast.error('Failed to copy to clipboard')
    }
  } else {
    downloadDeck(format, deck.deck, deck.entries)
  }
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
      <DeckInfoPanel @edit="onEdit" @export="onExport" />

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
</style>
