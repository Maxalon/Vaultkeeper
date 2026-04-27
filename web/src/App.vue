<script setup>
import { RouterView } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmModal from './components/ConfirmModal.vue'
import BulkImportProgress from './components/BulkImportProgress.vue'
</script>

<template>
  <RouterView />
  <ToastHost />
  <ConfirmModal />
  <BulkImportProgress />
</template>
