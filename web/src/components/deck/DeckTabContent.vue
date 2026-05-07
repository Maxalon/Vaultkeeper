<script setup>
import { computed, provide, ref, watch, onBeforeUnmount } from 'vue'
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
import { useNotificationsStore } from '../../stores/notifications'

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

// ── C4: Watch notifications store for friend.visibility_changed ──────
// When a friend revokes collection visibility mid-session, the notifications
// store (stream-b B1) pushes a new item of type 'friend.visibility_changed'.
// We watch for it and re-fetch the match list, marking visibilityRevoked
// so the panels can show the dedicated revoked state.
// The stub notifications store (replaced by B1) has an empty items array,
// so this watcher is a no-op until B1 lands.
const notifications = useNotificationsStore()

watch(
  () => notifications.items,
  (items) => {
    const hasRevoke = items.some(
      (n) => n.type === 'friend.visibility_changed' && !n.read_at,
    )
    if (hasRevoke && !wm.visibilityRevoked.value) {
      wm.markVisibilityRevoked()
      const deckId = deck.deck?.id
      if (deckId) {
        wm.reset()
        wm.fetch(deckId)
      }
    }
  },
  { deep: true },
)

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

// ── C4: Infer "no visible friends" state ─────────────────────────────
// When the user has friends (friendCount > 0) but none appear in ANY
// wanted-match entry, it means all friends have collection_visibility =
// 'private'. Show a distinct state in WantedMatchPanel.
const noVisibleFriends = computed(() => {
  const count = wm.friendCount.value
  if (count === null || count === 0) return false
  if (wm.loading.value || wm.error.value) return false
  // If any match has at least one friend, visibility is working.
  return wm.matches.value.every((m) => m.friends.length === 0)
})

// ── Provide wm + openMatchPanel so WantedMatchSummaryTab (C3) can ────
// ── inject them without prop-drilling through LeafNode/tabRegistry. ──

// openMatchPanel accepts either a raw match object (from WantedMatchSummaryTab)
// or a deck-entry object (from the avatar stacks on DeckCardStrip/Tile).
function openMatchPanel(matchOrEntry) {
  // If the argument has a `scryfall_card_id` it's already a match object.
  // If it has `scryfall_id` it came from an entry click (C2 path).
  if (matchOrEntry && 'scryfall_card_id' in matchOrEntry) {
    activeMatch.value = matchOrEntry
  } else {
    onMatchOpen(matchOrEntry)
  }
}

provide('wm', wm)
provide('openMatchPanel', openMatchPanel)
provide('noVisibleFriends', noVisibleFriends)

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
    <!-- C4 props: friendCount + noVisibleFriends + visibilityRevoked give
         the panel enough context to show a distinct state for each scenario. -->
    <WantedMatchPanel
      v-if="activeMatch"
      :match="activeMatch"
      :loading="wm.loading"
      :error="wm.error"
      :friend-count="wm.friendCount.value"
      :no-visible-friends="noVisibleFriends"
      :visibility-revoked="wm.visibilityRevoked.value"
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
