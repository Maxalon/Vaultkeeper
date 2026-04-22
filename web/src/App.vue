<script setup>
import { RouterView } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmModal from './components/ConfirmModal.vue'
</script>

<template>
  <RouterView />
  <ToastHost />
  <ConfirmModal />
</template>
