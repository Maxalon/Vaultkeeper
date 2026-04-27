<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '../stores/collection'
import { useDeckStore } from '../stores/deck'
import AppTopBar from '../components/AppTopBar.vue'
import LocationSidebar from '../components/LocationSidebar.vue'
import TabSystem from '../components/tabs/TabSystem.vue'
import DeckDetailSidebar from '../components/deck/DeckDetailSidebar.vue'
import DeckRemoveDropZone from '../components/deck/DeckRemoveDropZone.vue'

const collection = useCollectionStore()
const deck = useDeckStore()
const route = useRoute()
const router = useRouter()
const sidebarCollapsed = ref(false)

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

onMounted(async () => {
  await collection.fetchLocations()
  // fetchDecks runs inside the collection store (see Part 8). Guard in case
  // that method is added later — call only if present.
  if (typeof collection.fetchDecks === 'function') {
    await collection.fetchDecks()
  }
  await loadAll(route.params.id)
})

watch(
  () => route.params.id,
  async (id) => {
    if (id) await loadAll(id)
  },
)

onBeforeUnmount(() => {
  deck.reset()
})
</script>

<template>
  <div
    class="deck-shell"
    :class="{ 'detail-open': deck.activeEntryId !== null }"
    :data-sidebar="sidebarCollapsed ? 'collapsed' : 'expanded'"
  >
    <AppTopBar
      mode="deck"
      :sidebar-collapsed="sidebarCollapsed"
      @toggle-sidebar="sidebarCollapsed = !sidebarCollapsed"
    />
    <LocationSidebar :collapsed="sidebarCollapsed" />
    <main class="deck-main">
      <div v-if="deck.loading && !deck.deck" class="deck-skeleton">Loading deck…</div>
      <template v-else>
        <TabSystem class="deck-tabs" />
        <DeckDetailSidebar v-if="deck.activeEntryId !== null" />
      </template>
    </main>
    <DeckRemoveDropZone />
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
}
.deck-shell[data-sidebar="collapsed"] { --sidebar-width: 56px; }
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
