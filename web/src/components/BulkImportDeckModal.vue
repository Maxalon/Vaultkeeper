<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useBulkImportStore } from '../stores/bulkImport'

const emit = defineEmits(['close'])
const bulkImport = useBulkImportStore()

const source = ref('archidekt') // 'archidekt' | 'moxfield'
const input = ref('')
const submitting = ref(false)
const firstInput = ref(null)

// Detect source from a profile URL so the toggle reflects what was pasted.
function syncSourceFromInput() {
  const v = input.value.trim().toLowerCase()
  if (v.includes('archidekt.com/u/')) source.value = 'archidekt'
  else if (v.includes('moxfield.com/users/') || v.includes('moxfield.com/user/')) source.value = 'moxfield'
}

const canSubmit = computed(() => !submitting.value && input.value.trim().length > 0)

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => firstInput.value?.focus?.())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  await bulkImport.start({ source: source.value, username: input.value.trim() })
  submitting.value = false
  // The popup carries the rest of the experience; modal can step aside.
  emit('close')
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="bulk-import-title"
    >
      <h2 id="bulk-import-title" class="display">Bulk import from user</h2>

      <p class="hint">
        Imports every public deck from an Archidekt or Moxfield profile.
        Folders on the source become groups in Vaultkeeper.
      </p>

      <form @submit.prevent="submit">
        <div class="field">
          <span class="label">Source</span>
          <div class="source-toggle" role="group" aria-label="Source">
            <button
              type="button"
              class="src-btn"
              :class="{ active: source === 'archidekt' }"
              @click="source = 'archidekt'"
            >Archidekt</button>
            <button
              type="button"
              class="src-btn"
              :class="{ active: source === 'moxfield' }"
              @click="source = 'moxfield'"
            >Moxfield</button>
          </div>
        </div>

        <label class="field">
          <span class="label">Username or profile URL</span>
          <input
            ref="firstInput"
            v-model="input"
            type="text"
            placeholder="maxalon  or  https://archidekt.com/u/maxalon"
            @input="syncSourceFromInput"
          />
        </label>

        <div class="actions">
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="!canSubmit">
            {{ submitting ? 'Starting…' : 'Start import' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal-card {
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-top: 2px solid var(--gold);
  border-radius: 6px;
  width: 460px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--gold);
  margin-bottom: 8px;
}
.hint {
  color: var(--text-dim, var(--ink-50));
  font-size: 12px;
  margin: 0 0 16px;
  line-height: 1.5;
}
.field { display: block; margin-bottom: 14px; }
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-dim, var(--ink-50));
  margin-bottom: 5px;
}
.source-toggle {
  display: flex;
  gap: 4px;
  background: var(--bg-2);
  border: 1px solid var(--hairline, var(--border));
  border-radius: 6px;
  padding: 3px;
  width: fit-content;
}
.src-btn {
  background: transparent;
  border: 0;
  color: var(--text-dim, var(--ink-50));
  padding: 5px 14px;
  font-size: 12px;
  letter-spacing: 0.04em;
  border-radius: 4px;
  cursor: pointer;
}
.src-btn.active {
  background: var(--amber, var(--gold));
  color: #1a1408;
  font-weight: 600;
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
</style>
