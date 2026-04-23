<script setup>
import { onMounted, ref } from 'vue'
import { useCollectionStore } from '../stores/collection'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'
import StatsBar from '../components/StatsBar.vue'
import CardListPanel from '../components/CardListPanel.vue'
import DetailSidebar from '../components/DetailSidebar.vue'

const collection = useCollectionStore()
const sidebarCollapsed = ref(false)

onMounted(async () => {
  await collection.fetchLocations()
  if (collection.activeLocationId === null) {
    await collection.fetchEntries()
  }
})
</script>

<template>
  <div
    class="collection-shell"
    :class="{ 'detail-open': collection.activeEntryId !== null }"
    :data-sidebar="sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="collection"
      :sidebar-collapsed="sidebarCollapsed"
      @toggle-sidebar="sidebarCollapsed = !sidebarCollapsed"
    />
    <LocationSidebar :collapsed="sidebarCollapsed" />
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
  transition: grid-template-columns 200ms ease;
}
.collection-shell[data-sidebar="collapsed"] {
  --sidebar-width: 56px;
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
  background: var(--vk-bg-0);
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
