<script setup>
import AppTopBar from '../components/AppTopBar.vue'
import CatalogPanel from '../components/CatalogPanel.vue'
import CatalogDetailSidebar from '../components/CatalogDetailSidebar.vue'
</script>

<template>
  <div class="catalog-shell">
    <AppTopBar />
    <main class="main-area">
      <div class="main-body">
        <CatalogPanel />
        <CatalogDetailSidebar />
      </div>
    </main>
  </div>
</template>

<style scoped>
.catalog-shell {
  display: grid;
  grid-template-rows: 56px 1fr;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
}
.main-area {
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
</style>
