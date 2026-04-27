<script setup>
import { computed } from 'vue'
import { useBulkImportStore } from '../stores/bulkImport'

const bulkImport = useBulkImportStore()

const isDone   = computed(() => bulkImport.state === 'done')
const isFailed = computed(() => bulkImport.state === 'failed')
const isBusy   = computed(() => bulkImport.state === 'queued' || bulkImport.state === 'running')

const title = computed(() => {
  if (isDone.value)   return 'Import complete'
  if (isFailed.value) return 'Import failed'
  return 'Importing decks…'
})
</script>

<template>
  <Teleport to="body">
    <Transition name="bulk-pop">
      <div
        v-if="bulkImport.visible"
        class="bulk-popup"
        :class="{ done: isDone, failed: isFailed }"
        role="status"
        aria-live="polite"
      >
        <div class="icon" aria-hidden="true">
          <svg v-if="isBusy" class="spinner" viewBox="0 0 32 32" width="28" height="28">
            <circle cx="16" cy="16" r="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                    stroke-dasharray="50 25"></circle>
          </svg>
          <svg v-else-if="isDone" class="check" viewBox="0 0 32 32" width="28" height="28">
            <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" stroke-width="2"></circle>
            <path d="M9 16l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3"
                  stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
          <svg v-else class="cross" viewBox="0 0 32 32" width="28" height="28">
            <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" stroke-width="2"></circle>
            <path d="M11 11l10 10M21 11L11 21" fill="none" stroke="currentColor" stroke-width="3"
                  stroke-linecap="round"></path>
          </svg>
        </div>
        <div class="body">
          <div class="title">{{ title }}</div>
          <div class="msg">{{ bulkImport.message }}</div>
        </div>
        <button type="button" class="close" @click="bulkImport.dismiss()" aria-label="Dismiss">×</button>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.bulk-popup {
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  z-index: 9998;
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 280px;
  max-width: 380px;
  padding: 14px 16px;
  background: var(--bg-1, #1d1c1a);
  color: var(--ink-90, #e9e4d6);
  border: 1px solid var(--hairline, rgba(255, 255, 255, 0.08));
  border-left: 3px solid var(--amber, #c99d3d);
  border-radius: 8px;
  box-shadow: 0 12px 36px rgba(0, 0, 0, 0.5);
  font-family: var(--font-sans), sans-serif;
}
.bulk-popup.done   { border-left-color: #5e9a5c; }
.bulk-popup.failed { border-left-color: #d15a4a; }

.icon {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--amber, #c99d3d);
}
.bulk-popup.done .icon   { color: #6fb46d; }
.bulk-popup.failed .icon { color: #e07064; }

.spinner { animation: bulk-spin 0.9s linear infinite; }
@keyframes bulk-spin {
  to { transform: rotate(360deg); }
}

.body {
  flex: 1;
  min-width: 0;
}
.title {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.02em;
  margin-bottom: 2px;
}
.msg {
  font-size: 12px;
  color: var(--ink-50, #b6b09e);
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.close {
  background: transparent;
  border: 0;
  color: var(--ink-30, #7a766b);
  font-size: 18px;
  line-height: 1;
  cursor: pointer;
  padding: 0 4px;
  align-self: flex-start;
}
.close:hover { color: var(--ink-100, #f3eddc); }

.bulk-pop-enter-from, .bulk-pop-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
.bulk-pop-enter-active, .bulk-pop-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
</style>
