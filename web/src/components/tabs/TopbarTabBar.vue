<script setup>
import { computed } from 'vue'
import { useTabsStore } from '../../stores/tabs'
import TabBar from './TabBar.vue'

const tabs = useTabsStore()

// Only hoist into the topbar when the root is a single leaf. As soon as
// the user splits, each leaf renders its own inline bar and the topbar
// center goes empty.
const rootLeaf = computed(() =>
  tabs.root && tabs.root.type === 'leaf' ? tabs.root : null,
)

function onOpenCatalog(panelId) {
  tabs.openTab('catalog', {}, panelId)
}
</script>

<template>
  <TabBar
    v-if="rootLeaf"
    :node="rootLeaf"
    variant="topbar"
    @open-catalog="onOpenCatalog"
  />
</template>

<style scoped>
/* Reach into TabBar's own classes to adapt them to the topbar context. */
:deep(.tab-bar.variant-topbar) {
  border-bottom: none;
  background: transparent;
  padding: 0;
  height: 100%;
  align-items: center;
  flex: 1 1 auto;
  min-width: 0; /* lets overflow-x: auto actually clip */
}
</style>
