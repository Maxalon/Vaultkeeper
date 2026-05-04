<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useCollectionStore } from '../stores/collection'

// `assemble` fires after a successful import when the user pre-ticked
// the "already assembled" toggle — parent opens AssembleDeckModal.
const emit = defineEmits(['close', 'assemble'])
const collection = useCollectionStore()
const router = useRouter()

const file = ref(null)
const name = ref('')
const format = ref('commander')
// Per locked decision 7, the toggle is a SPA-side concern: the import
// POST never receives it. After a successful import we surface a
// "Mark as assembled" CTA on the result panel so the user can hand
// off to AssembleDeckModal.
const assembled = ref(false)

const submitting = ref(false)
const error = ref('')
const result = ref(null)
const dropZone = ref(null)
const firstInput = ref(null)

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => firstInput.value?.focus?.())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

function onFileChange(e) {
  file.value = e.target.files[0] || null
  error.value = ''
  result.value = null
  if (file.value && !name.value.trim()) {
    // Default the deck name to the upload's filename minus the extension
    // — saves a step for users who exported "Mono Red Burn.csv" from
    // somewhere that already had a usable name.
    name.value = file.value.name.replace(/\.[^.]+$/, '').slice(0, 100)
  }
}

function dropFile(e) {
  const f = e.dataTransfer.files[0]
  if (f) {
    file.value = f
    error.value = ''
    result.value = null
    if (!name.value.trim()) {
      name.value = f.name.replace(/\.[^.]+$/, '').slice(0, 100)
    }
  }
}

const canSubmit = computed(() => {
  if (submitting.value) return false
  return !!(file.value && name.value.trim())
})

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = ''
  result.value = null
  try {
    const form = new FormData()
    form.append('csv_file', file.value)
    form.append('name', name.value.trim())
    form.append('format', format.value)
    result.value = await collection.importDeckCsv(form)
    // assembled.value is intentionally not sent — assemble flow lands
    // in a follow-up branch.
  } catch (e) {
    if (e.response?.status === 422) {
      const errs = e.response.data.errors
      error.value = errs
        ? Object.values(errs).flat().join('; ')
        : e.response.data.message || 'Validation failed'
    } else {
      error.value = e.response?.data?.message || 'Import failed'
    }
  } finally {
    submitting.value = false
  }
}

function openDeck() {
  const id = result.value?.deck?.id
  emit('close')
  if (id) router.push({ name: 'deck', params: { id } })
}

function requestAssemble() {
  const deck = result.value?.deck
  if (!deck) return
  emit('assemble', { id: deck.id, name: deck.name })
  emit('close')
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="import-deck-csv-title"
    >
      <h2 id="import-deck-csv-title" class="display">Import Deck from CSV</h2>

      <form v-if="!result" @submit.prevent="submit">
        <div
          ref="dropZone"
          class="drop-zone"
          :class="{ 'has-file': file }"
          tabindex="0"
          @dragover.prevent
          @drop.prevent="dropFile"
          @click="$refs.fileInput.click()"
          @keydown.enter.prevent="$refs.fileInput.click()"
        >
          <input
            ref="fileInput"
            name="deck-csv-file"
            type="file"
            accept=".csv,.txt"
            hidden
            @change="onFileChange"
          />
          <template v-if="file">
            <span class="file-name">{{ file.name }}</span>
            <span class="file-size">{{ (file.size / 1024).toFixed(1) }} KB</span>
          </template>
          <template v-else>
            <span class="drop-label">Drop a ManaBox CSV here or click to browse</span>
            <span class="drop-hint">CSV or TXT, max 5 MB. Optional <code>Zone</code> column splits cards across main / side / maybe.</span>
          </template>
        </div>

        <label class="field">
          <span class="label">Deck name</span>
          <input
            ref="firstInput"
            name="deck-name"
            autocomplete="off"
            v-model="name"
            type="text"
            maxlength="100"
          />
        </label>

        <label class="field">
          <span class="label">Format</span>
          <select v-model="format" name="format">
            <option value="commander">Commander</option>
            <option value="oathbreaker">Oathbreaker</option>
            <option value="pauper">Pauper</option>
            <option value="standard">Standard</option>
            <option value="modern">Modern</option>
          </select>
        </label>

        <label class="assembled-row">
          <input
            type="checkbox"
            v-model="assembled"
            name="deck-assembled"
          />
          <span class="assembled-label">
            This deck is already assembled
            <span
              class="assembled-hint"
              tabindex="0"
              role="img"
              aria-label="Tick this if you physically own the cards in this deck. We'll log them in your collection under 'Deck: <name>' so they don't show up in your bulk or binders."
              title="Tick this if you physically own the cards in this deck. We'll log them in your collection under 'Deck: <name>' so they don't show up in your bulk or binders."
            >?</span>
          </span>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="!canSubmit">
            {{ submitting ? 'Importing…' : 'Import' }}
          </button>
        </div>
      </form>

      <div v-else class="result">
        <div class="stats">
          <div class="stat">
            <span class="stat-val">{{ result.imported }}</span>
            <span class="stat-lbl">Imported</span>
          </div>
          <div class="stat">
            <span class="stat-val">{{ result.skipped }}</span>
            <span class="stat-lbl">Skipped</span>
          </div>
          <div class="stat">
            <span class="stat-val">{{ result.warnings?.length || 0 }}</span>
            <span class="stat-lbl">Warnings</span>
          </div>
        </div>

        <ul v-if="result.warnings?.length" class="warnings">
          <li v-for="(w, i) in result.warnings" :key="i">{{ w }}</li>
        </ul>

        <div class="deck-summary">
          Created <strong>{{ result.deck?.name }}</strong>
          <span class="format-badge">{{ result.deck?.format }}</span>
        </div>

        <div class="actions">
          <button type="button" @click="emit('close')">Close</button>
          <button v-if="!assembled" type="button" class="primary" @click="openDeck">Open Deck</button>
          <template v-else>
            <button type="button" @click="openDeck">Open Deck</button>
            <button type="button" class="primary" @click="requestAssemble">Mark as assembled</button>
          </template>
        </div>
      </div>
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
  border: 1px solid var(--hairline);
  border-top: 2px solid var(--amber);
  border-radius: 6px;
  width: 460px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--amber);
  margin-bottom: 14px;
}
.drop-zone {
  border: 2px dashed var(--hairline);
  border-radius: 6px;
  padding: 24px 16px;
  text-align: center;
  cursor: pointer;
  margin-bottom: 14px;
  transition: border-color 150ms ease, background 150ms ease;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.drop-zone:hover {
  border-color: var(--amber-lo);
  background: rgba(201, 162, 39, 0.04);
}
.drop-zone.has-file {
  border-color: var(--amber);
  background: rgba(201, 162, 39, 0.07);
}
.drop-label {
  font-size: 13px;
  color: var(--ink-50);
}
.drop-hint {
  font-size: 11px;
  color: var(--ink-30);
  line-height: 1.4;
  max-width: 320px;
}
.drop-hint code {
  font-family: ui-monospace, SFMono-Regular, 'Menlo', monospace;
  font-size: 10.5px;
  background: var(--bg-0);
  padding: 1px 4px;
  border-radius: 3px;
  color: var(--ink-50);
}
.file-name {
  font-size: 13px;
  color: var(--ink-100);
  font-weight: 600;
}
.file-size {
  font-size: 11px;
  color: var(--ink-50);
}
.field { display: block; margin-bottom: 14px; }
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin-bottom: 5px;
}
.assembled-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 6px 0 4px;
  font-size: 13px;
  color: var(--ink-100);
  cursor: pointer;
}
.assembled-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.assembled-hint {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--bg-0);
  color: var(--ink-50);
  font-size: 10px;
  font-weight: 700;
  cursor: help;
  border: 1px solid var(--hairline);
}
.assembled-hint:focus { outline: 1px solid var(--amber); outline-offset: 1px; }
.error { color: var(--cond-dmg); margin: 4px 0 12px; font-size: 12px; }
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
.result { padding-top: 4px; }
.stats { display: flex; gap: 12px; margin-bottom: 16px; }
.stat {
  flex: 1;
  text-align: center;
  background: var(--bg-0);
  border-radius: 6px;
  padding: 14px 8px;
}
.stat-val {
  display: block;
  font-size: 24px;
  font-weight: 700;
  color: var(--amber);
  font-family: var(--font-display), serif;
}
.stat-lbl {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin-top: 4px;
}
.warnings {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  max-height: 160px;
  overflow-y: auto;
  font-size: 12px;
  color: var(--ink-50);
}
.warnings li {
  padding: 4px 0;
  border-bottom: 1px solid var(--hairline);
}
.warnings li::before { content: '\26A0\FE0F  '; }
.deck-summary {
  padding: 10px 12px;
  background: var(--bg-0);
  border-radius: 6px;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.format-badge {
  text-transform: uppercase;
  font-size: 10px;
  letter-spacing: 0.05em;
  color: var(--ink-50);
  background: var(--bg-1);
  padding: 2px 8px;
  border-radius: 999px;
}
</style>
