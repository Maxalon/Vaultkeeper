<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useDeckStore } from '../../stores/deck'
import DeckInfoPanel from './DeckInfoPanel.vue'
import DeckFilterBar from './DeckFilterBar.vue'
import DeckGrid from './DeckGrid.vue'
import ZoneDivider from './ZoneDivider.vue'
import LocationModal from '../LocationModal.vue'
import WantedMatchPanel from './matcher/WantedMatchPanel.vue'
import { copyDeckToClipboard, downloadDeck } from '../../utils/deckExport'
import { useToast } from '../../composables/useToast'
import { useWantedMatches } from '../../composables/useWantedMatches'

defineProps({
  zone: { type: String, default: null },  // when set (side|maybe), render only that zone
})

const deck = useDeckStore()
const toast = useToast()

// ── Wanted matches (C1/C2) ──────────────────────────────────────────
const wm = useWantedMatches()

// Fetch matches once the deck id is available, and re-fetch when the
// deck changes (e.g. navigating between decks in the same tab).
watch(
  () => deck.deck?.id,
  (id) => {
    wm.reset()
    if (id) wm.fetch(id)
  },
  { immediate: true },
)

onBeforeUnmount(() => wm.reset())

// Active match side panel (C2). Set when user clicks an avatar stack.
const activeMatch = ref(null)

function onMatchOpen(entry) {
  const match = wm.matchFor(entry.scryfall_id)
  // Always open the panel, even if there are 0 friends — shows empty state.
  activeMatch.value = match ?? {
    scryfall_card_id: entry.scryfall_id,
    card_name: entry.scryfall_card?.name ?? '',
    wanted_quantity: entry.wanted_quantity ?? 1,
    friends: [],
  }
}

function closeMatchPanel() {
  activeMatch.value = null
}

// ── Deck edit ───────────────────────────────────────────────────────
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
  <div v-if="deck.deck" class="deck-tab-content" :class="{ 'zone-only': !!zone, 'panel-open': !!activeMatch }">
    <!-- Grid + match panel share horizontal space via flex. -->
    <div class="dtc-body">
      <!-- Zone-scoped undocked view -->
      <template v-if="zone">
        <DeckFilterBar />
        <DeckGrid
          :zone="zone"
          fill
          :match-for="wm.matchFor"
          :match-loading="wm.loading"
          @match-open="onMatchOpen"
        />
      </template>

      <!-- Full deck layout -->
      <template v-else>
        <DeckInfoPanel @edit="onEdit" @export="onExport" />

        <DeckFilterBar />

        <DeckGrid
          zone="main"
          :match-for="wm.matchFor"
          :match-loading="wm.loading"
          @match-open="onMatchOpen"
        />

        <template v-if="!deck.sideUndocked">
          <ZoneDivider zone="side" />
          <DeckGrid
            zone="side"
            :match-for="wm.matchFor"
            :match-loading="wm.loading"
            @match-open="onMatchOpen"
          />
        </template>

        <template v-if="!deck.maybeUndocked">
          <ZoneDivider zone="maybe" />
          <DeckGrid
            zone="maybe"
            :match-for="wm.matchFor"
            :match-loading="wm.loading"
            @match-open="onMatchOpen"
          />
        </template>
      </template>
    </div>

    <!-- C2: side panel, mounted when an avatar stack is clicked -->
    <WantedMatchPanel
      v-if="activeMatch"
      :match="activeMatch"
      :loading="wm.loading"
      :error="wm.error"
      @close="closeMatchPanel"
    />
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
  overflow: hidden;
  display: flex;
  flex-direction: row;
}

/* The scrollable body that holds info panel + filter bar + grids. */
.dtc-body {
  flex: 1 1 auto;
  min-width: 0;
  overflow-y: auto;
}

/* Make the filter bar + grid a flex column so the grid (with its `fill`
   class) can flex-grow into all leftover height. Drops anywhere in the
   tab — including the empty area below the last card — then land in
   this zone. */
.deck-tab-content.zone-only .dtc-body {
  display: flex;
  flex-direction: column;
}

/* WantedMatchPanel sits beside the grid; it manages its own width via
   --detail-width (340px) and height via flex-shrink: 0. The DeckView
   shell already sets the outer right-rail width — this panel replaces
   the catalog/deck-detail sidebar in the same flex row. */
.deck-tab-content :deep(.wmp) {
  flex-shrink: 0;
}
</style>
