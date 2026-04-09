<script setup>
import { onMounted } from 'vue'
import { useCollectionStore } from '../stores/collection'
import LocationSidebar from '../components/LocationSidebar.vue'
import CardListPanel from '../components/CardListPanel.vue'
import DetailSidebar from '../components/DetailSidebar.vue'

const collection = useCollectionStore()

onMounted(async () => {
  await collection.fetchLocations()
  // Default to "Unassigned" so the user immediately sees their cards.
  if (collection.activeLocationId === null) {
    await collection.setActiveLocation('unassigned')
  }
})
</script>

<template>
  <div class="collection-shell" :class="{ 'detail-open': collection.activeEntryId !== null }">
    <LocationSidebar />
    <CardListPanel />
    <DetailSidebar />
  </div>
</template>

<style scoped>
.collection-shell {
  display: grid;
  grid-template-columns: var(--sidebar-width) 1fr 0;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  transition: grid-template-columns 200ms ease;
}
.collection-shell.detail-open {
  grid-template-columns: var(--sidebar-width) 1fr var(--detail-width);
}
</style>
