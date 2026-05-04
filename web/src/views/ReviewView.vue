<script setup>
import { onMounted } from 'vue'
import { useCollectionStore } from '../stores/collection'
import { useSettingsStore } from '../stores/settings'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'
import ReviewList from '../components/review/ReviewList.vue'

/**
 * Global view of every review-flagged CE the user has. Dedicated route
 * per locked decision 12; the sidebar's review row links here. Reuses
 * the shell layout (sidebar + topbar) so nav stays consistent with the
 * rest of the app.
 */
const collection = useCollectionStore()
const settings = useSettingsStore()

onMounted(async () => {
  // Make sure the sidebar locations + review summary are populated —
  // both inform the resolve dropdown and the count badge.
  await collection.fetchLocations()
})
</script>

<template>
  <div
    class="review-shell"
    :data-sidebar="settings.sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="review"
      :sidebar-collapsed="settings.sidebarCollapsed"
      @toggle-sidebar="settings.toggleSidebarCollapsed()"
    />
    <LocationSidebar :collapsed="settings.sidebarCollapsed" />
    <main class="review-main">
      <ReviewList scope="global" />
    </main>
  </div>
</template>

<style scoped>
.review-shell {
  display: grid;
  grid-template-columns: var(--sidebar-width) 1fr;
  grid-template-rows: 56px 1fr;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  --brand-width: var(--sidebar-width);
  transition: grid-template-columns 200ms ease;
}
.review-shell[data-sidebar="collapsed"] {
  --sidebar-width: 0px;
  --brand-width: 96px;
}
.review-shell[data-sidebar="collapsed"] :deep(.location-sidebar) {
  display: none;
}
.review-shell :deep(.vk-topbar) {
  grid-column: 1 / -1;
  grid-row: 1;
}
.review-shell :deep(.location-sidebar) {
  grid-column: 1;
  grid-row: 2;
  min-height: 0;
}
.review-main {
  grid-column: 2;
  grid-row: 2;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  background: var(--bg-0);
  overflow: hidden;
}
</style>
