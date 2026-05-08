<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCatalogStore } from '../stores/catalog'
import { useCollectionStore } from '../stores/collection'
import { useDeckStore } from '../stores/deck'
import { useSettingsStore } from '../stores/settings'
import { useWantedMatchesStore } from '../stores/wantedMatches'
import { useNotificationsStore } from '../stores/notifications'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'
import TabSystem from '../components/tabs/TabSystem.vue'
import CatalogDetailSidebar from '../components/CatalogDetailSidebar.vue'
import DeckDetailSidebar from '../components/deck/DeckDetailSidebar.vue'
import DeckRemoveDropZone from '../components/deck/DeckRemoveDropZone.vue'
import DeckCreateCategoryDropZone from '../components/deck/DeckCreateCategoryDropZone.vue'
import WantedMatchPanel from '../components/deck/matcher/WantedMatchPanel.vue'

const catalog = useCatalogStore()
const collection = useCollectionStore()
const deck = useDeckStore()
const settings = useSettingsStore()
const wm = useWantedMatchesStore()
const notifications = useNotificationsStore()

// Right-rail arbitration. Catalog and deck-entry detail sidebars share the
// slot — opening one closes the other. Catalog wins when both are set
// because the user's most recent action selected a catalog row.
const catalogDetailOpen = computed(() => !!catalog.activeCardOracleId)
const deckDetailOpen = computed(
  () => deck.activeEntryId !== null && !catalogDetailOpen.value,
)
const detailOpen = computed(() => catalogDetailOpen.value || deckDetailOpen.value)

watch(catalogDetailOpen, (open) => {
  if (open && deck.activeEntryId !== null) deck.activeEntryId = null
})
watch(() => deck.activeEntryId, (id) => {
  if (id !== null && catalog.activeCardOracleId) catalog.clearActive()
})
const route = useRoute()
const router = useRouter()

async function loadAll(id) {
  try {
    await deck.loadDeck(id)
    await Promise.all([
      deck.loadEntries(id),
      deck.loadIllegalities(id),
    ])
  } catch (e) {
    if (e.response?.status === 404) {
      router.replace({ name: 'collection' })
    }
  }
}

// Wanted-matches lifecycle: fetch when the deck id is known, refetch on
// deck change, reset on unmount. The store is global so any tab —
// PhysicalCopiesTab, DeckDetailSidebar, WantedMatchSummaryTab — reads the
// same data without provide/inject.
watch(
  () => deck.deck?.id,
  (id) => {
    wm.reset()
    if (id) wm.fetch(id)
  },
  { immediate: true },
)

// Refetch and flag when a friend revokes collection visibility mid-session.
// stream-b B1 stub returns an empty items array, so this watcher is a
// no-op until that lands.
watch(
  () => notifications.items,
  (items) => {
    const hasRevoke = items.some(
      (n) => n.type === 'friend.visibility_changed' && !n.read_at,
    )
    if (hasRevoke && !wm.visibilityRevoked) {
      wm.markVisibilityRevoked()
      const id = deck.deck?.id
      if (id) {
        wm.reset()
        wm.fetch(id)
      }
    }
  },
  { deep: true },
)

// Belt-and-suspenders: clear the deck drag state on any dragend or drop
// at the document level. Cross-zone drops can rebuild the source list and
// unmount the dragged strip before its own dragend fires, leaving the
// floating remove / new-category drop zones stuck on screen.
function clearDragState() {
  if (deck.dragEntryId !== null) deck.setDragEntry(null)
}

onMounted(async () => {
  // The unified sidebar payload includes deck shadow rows with their
  // entry_count and commander preview, so a single fetchGroups gives both
  // the sidebar contents and the deck list.
  await collection.fetchGroups()
  await loadAll(route.params.id)
  document.addEventListener('dragend', clearDragState)
  document.addEventListener('drop', clearDragState)
})

watch(
  () => route.params.id,
  async (id) => {
    if (id) await loadAll(id)
  },
)

onBeforeUnmount(() => {
  document.removeEventListener('dragend', clearDragState)
  document.removeEventListener('drop', clearDragState)
  deck.reset()
  wm.reset()
})
</script>

<template>
  <div
    class="deck-shell"
    :class="{ 'detail-open': detailOpen }"
    :data-sidebar="settings.sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="deck"
      :sidebar-collapsed="settings.sidebarCollapsed"
      @toggle-sidebar="settings.toggleSidebarCollapsed()"
    />
    <LocationSidebar :collapsed="settings.sidebarCollapsed" />
    <main class="deck-main">
      <div v-if="deck.loading && !deck.deck" class="deck-skeleton">Loading deck…</div>
      <template v-else>
        <TabSystem class="deck-tabs" />
        <CatalogDetailSidebar v-if="catalogDetailOpen" />
        <DeckDetailSidebar v-else-if="deckDetailOpen" />
      </template>
    </main>
    <DeckRemoveDropZone />
    <DeckCreateCategoryDropZone />
    <!-- Slide-in friend-match panel. Mounted at the view root so any tab
         (PhysicalCopiesTab, DeckDetailSidebar, WantedMatchSummaryTab) can
         open it without competing for layout space. -->
    <WantedMatchPanel v-if="wm.activeMatch" @close="wm.closePanel" />
  </div>
</template>

<style scoped>
.deck-shell {
  display: grid;
  grid-template-columns: var(--sidebar-width) 1fr;
  grid-template-rows: 56px 1fr;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  /* Brand column tracks the sidebar width while expanded so the topbar
     stays aligned during a drag-resize. Collapsed state overrides only
     the brand width — the sidebar collapses to 0 and disappears. */
  --brand-width: var(--sidebar-width);
  transition: grid-template-columns 200ms ease;
}
.deck-shell[data-sidebar="collapsed"] {
  --sidebar-width: 0px;
  --brand-width: 96px;
}
.deck-shell[data-sidebar="collapsed"] :deep(.location-sidebar) {
  display: none;
}
.deck-shell :deep(.vk-topbar) {
  grid-column: 1 / -1;
  grid-row: 1;
}
.deck-shell :deep(.location-sidebar) {
  grid-column: 1;
  grid-row: 2;
  min-height: 0;
}
.deck-main {
  grid-column: 2;
  grid-row: 2;
  min-width: 0;
  min-height: 0;
  display: flex;
  background: var(--bg-0);
  overflow: hidden;
}
.deck-tabs {
  flex: 1 1 auto;
  min-width: 0;
}
.deck-main :deep(.deck-detail-sidebar) {
  width: var(--detail-width, 340px);
  flex-shrink: 0;
  border-left: 1px solid var(--hairline, #33312c);
  overflow: auto;
}
.deck-skeleton {
  margin: 2rem auto;
  color: var(--ink-70, #a8a396);
}
</style>
