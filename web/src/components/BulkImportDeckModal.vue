<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useBulkImportStore } from '../stores/bulkImport'

const emit = defineEmits(['close'])
const bulkImport = useBulkImportStore()

// Archidekt-only for now. Moxfield's user-deck search requires a per-user
// JWT (see PR notes), so bulk import isn't viable there. Single-deck
// Moxfield URL imports still work via the regular Import Deck modal.
const input = ref('')
const onDuplicate = ref('skip') // 'skip' | 'update'
const submitting = ref(false)
const firstInput = ref(null)

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
  await bulkImport.start({
    source: 'archidekt',
    username: input.value.trim(),
    onDuplicate: onDuplicate.value,
  })
  submitting.value = false
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
      <h2 id="bulk-import-title" class="display">Bulk import from Archidekt</h2>

      <p class="hint">
        Imports every public deck from an Archidekt profile. Folders on
        Archidekt become groups in Vaultkeeper.
      </p>

      <form @submit.prevent="submit">
        <label class="field">
          <span class="label">Username or profile URL</span>
          <input
            ref="firstInput"
            v-model="input"
            type="text"
            placeholder="your-username  or  https://archidekt.com/u/your-username"
          />
        </label>

        <fieldset class="field dup-choice">
          <legend class="label">When a deck has already been imported</legend>
          <label class="dup-opt">
            <input type="radio" v-model="onDuplicate" value="skip" />
            <span class="dup-opt-body">
              <span class="dup-opt-title">Skip it</span>
              <span class="dup-opt-hint">Leave existing imports untouched (default).</span>
            </span>
          </label>
          <label class="dup-opt">
            <input type="radio" v-model="onDuplicate" value="update" />
            <span class="dup-opt-body">
              <span class="dup-opt-title">Update with latest from Archidekt</span>
              <span class="dup-opt-hint">Overwrite cards / commanders / format. Group placement is kept.</span>
            </span>
          </label>
        </fieldset>

        <p class="note">
          Moxfield isn't supported in bulk — their API requires a personal
          login token. Use <strong>Import Deck</strong> to add Moxfield decks one
          at a time.
        </p>

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
.note {
  margin: 4px 0 0;
  padding: 10px 12px;
  background: var(--bg-2);
  border-left: 2px solid var(--ink-30, var(--text-dim));
  border-radius: 0 4px 4px 0;
  font-size: 11px;
  line-height: 1.5;
  color: var(--text-dim, var(--ink-50));
}
.dup-choice {
  border: 1px solid var(--hairline, var(--border));
  border-radius: 6px;
  padding: 10px 12px 4px;
}
.dup-choice .label {
  padding: 0 4px;
  font-size: 10px;
}
.dup-opt {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 6px 4px;
  cursor: pointer;
}
.dup-opt input { margin-top: 3px; flex-shrink: 0; }
.dup-opt-body { display: flex; flex-direction: column; gap: 2px; }
.dup-opt-title {
  font-size: 12px;
  color: var(--ink-90, var(--ink-100));
}
.dup-opt-hint {
  font-size: 11px;
  color: var(--text-dim, var(--ink-50));
  line-height: 1.4;
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
</style>
