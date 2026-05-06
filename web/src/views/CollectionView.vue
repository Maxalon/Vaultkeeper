<script setup>
import { onMounted, watch } from 'vue'
import { useCollectionStore } from '../stores/collection'
import { usePricesStore } from '../stores/prices'
import { useSettingsStore } from '../stores/settings'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'
import StatsBar from '../components/StatsBar.vue'
import CardListPanel from '../components/CardListPanel.vue'
import DetailSidebar from '../components/DetailSidebar.vue'

const collection = useCollectionStore()
const prices = usePricesStore()
const settings = useSettingsStore()

// Totals track whatever location the user is currently viewing — null
// (the "All Cards" pseudo-location) gets the collection-wide total,
// any numeric id constrains to that drawer/binder. Re-fetch on every
// active-location change so the StatsBar doesn't lag behind the list.
function refreshTotals() {
  const loc = collection.activeLocationId
  if (loc === null) {
    prices.fetchCollectionTotals().catch(() => {})
  } else {
    prices.fetchCollectionTotals(loc).catch(() => {})
  }
}

onMounted(async () => {
  await collection.fetchLocations()
  if (collection.activeLocationId === null) {
    await collection.fetchEntries()
  }
  // Fire-and-forget: the totals + last-synced hint render once they
  // arrive; we don't block the collection list on them.
  prices.fetchStatus().catch(() => {})
  refreshTotals()
})

watch(() => collection.activeLocationId, refreshTotals)
</script>

<template>
  <div
    class="collection-shell"
    :class="{ 'detail-open': collection.activeEntryId !== null }"
    :data-sidebar="settings.sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="collection"
      :sidebar-collapsed="settings.sidebarCollapsed"
      @toggle-sidebar="settings.toggleSidebarCollapsed()"
    />
    <LocationSidebar :collapsed="settings.sidebarCollapsed" />
    <main class="main-area">
      <StatsBar />
      <div class="main-body">
        <CardListPanel />
        <DetailSidebar />
      </div>
    </main>
  </div>
</template>

<style scoped>
.collection-shell {
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
.collection-shell[data-sidebar="collapsed"] {
  --sidebar-width: 0px;
  --brand-width: 96px;
}
.collection-shell[data-sidebar="collapsed"] :deep(.location-sidebar) {
  display: none;
}
.collection-shell :deep(.vk-topbar) {
  grid-column: 1 / -1;
  grid-row: 1;
}
.collection-shell :deep(.location-sidebar) {
  grid-column: 1;
  grid-row: 2;
  min-height: 0;
}
.main-area {
  grid-column: 2;
  grid-row: 2;
  display: flex;
  flex-direction: column;
  background: var(--bg-0);
  min-width: 0;
  overflow: hidden;
}
.main-body {
  flex: 1;
  display: flex;
  min-height: 0;
  overflow: hidden;
}
.main-body :deep(.card-list-panel) {
  flex: 1;
  min-width: 0;
}
.main-body :deep(.detail-sidebar),
.main-body :deep(.vk-detail) {
  width: var(--detail-width);
  flex-shrink: 0;
}
</style>
