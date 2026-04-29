<script setup>
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmModal from './components/ConfirmModal.vue'
import BulkImportProgress from './components/BulkImportProgress.vue'
import OnboardingWelcome from './components/OnboardingWelcome.vue'
import { useAuthStore } from './stores/auth'
import { useBulkImportStore } from './stores/bulkImport'

const auth = useAuthStore()
const bulkImport = useBulkImportStore()

// Resume any bulk import that was running when the user reloaded mid-job
// (or open a fresh tab and see the just-finished result for ~5s).
onMounted(async () => {
  // After a page reload the token is restored from localStorage but the
  // user record isn't, so we re-fetch it. This also drives the onboarding
  // modal — we can't know whether to show it until we know the user.
  if (auth.isAuthenticated && !auth.user) {
    try {
      await auth.fetchMe()
    } catch (e) {
      // 401 → axios interceptor clears the token; nothing to do here.
    }
  }
  bulkImport.resumeIfActive()
})
</script>

<template>
  <RouterView />
  <ToastHost />
  <ConfirmModal />
  <BulkImportProgress />
  <OnboardingWelcome />
</template>
