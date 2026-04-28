<script setup>
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmModal from './components/ConfirmModal.vue'
import BulkImportProgress from './components/BulkImportProgress.vue'
import { useBulkImportStore } from './stores/bulkImport'

// Resume any bulk import that was running when the user reloaded mid-job
// (or open a fresh tab and see the just-finished result for ~5s).
const bulkImport = useBulkImportStore()
onMounted(() => bulkImport.resumeIfActive())
</script>

<template>
  <RouterView />
  <ToastHost />
  <ConfirmModal />
  <BulkImportProgress />
</template>
