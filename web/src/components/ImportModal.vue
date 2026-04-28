<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useCollectionStore } from '../stores/collection'
import LocationModal from './LocationModal.vue'
import api from '../lib/api'

const emit = defineEmits(['close'])
const collection = useCollectionStore()

const file = ref(null)
const locationId = ref('')
const submitting = ref(false)
const error = ref('')
const result = ref(null)
const showLocationModal = ref(false)
const prevLocationId = ref('')
const dropZone = ref(null)

function onKeydown(e) {
  if (e.key === 'Escape' && !showLocationModal.value) emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => dropZone.value?.focus())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const realLocations = computed(() => collection.locations)

const CREATE_NEW = '__create_new__'

function onLocationChange(e) {
  if (e.target.value === CREATE_NEW) {
    prevLocationId.value = locationId.value
    showLocationModal.value = true
    // Reset select back so it doesn't show the "+ Create" text
    e.target.value = locationId.value
  } else {
    locationId.value = e.target.value
  }
}

function onLocationCreated(newLocation) {
  locationId.value = newLocation.id
  showLocationModal.value = false
}

function onLocationModalClose() {
  if (showLocationModal.value) {
    locationId.value = prevLocationId.value
    showLocationModal.value = false
  }
}

function onFileChange(e) {
  file.value = e.target.files[0] || null
  error.value = ''
  result.value = null
}

function dropFile(e) {
  const f = e.dataTransfer.files[0]
  if (f) {
    file.value = f
    error.value = ''
    result.value = null
  }
}

async function submit() {
  if (!file.value) {
    error.value = 'Please select a CSV file'
    return
  }
  submitting.value = true
  error.value = ''
  result.value = null
  try {
    const form = new FormData()
    form.append('csv_file', file.value)
    if (locationId.value) form.append('location_id', locationId.value)
    const { data } = await api.post('/import', form)
    result.value = data
    await collection.fetchLocations()
    await collection.fetchEntries()
  } catch (e) {
    if (e.response?.status === 422) {
      const errors = e.response.data.errors
      error.value = errors
        ? Object.values(errors).flat().join('; ')
        : e.response.data.message || 'Validation failed'
    } else {
      error.value = e.response?.data?.message || 'Import failed'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      :class="{ dimmed: showLocationModal }"
      role="dialog"
      aria-modal="true"
      aria-labelledby="import-modal-title"
    >
      <h2 id="import-modal-title" class="display">Import Cards</h2>

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
            name="import-file"
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
            <span class="drop-hint">CSV or TXT, max 5 MB</span>
          </template>
        </div>

        <label class="field">
          <span class="label">Location <span class="hint">(optional)</span></span>
          <select id="import-location" :value="locationId" @change="onLocationChange">
            <option value="" disabled selected>Select location…</option>
            <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">
              {{ loc.name }}
            </option>
            <option disabled class="create-separator">────────────</option>
            <option :value="CREATE_NEW">+ Create new location</option>
          </select>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="submitting || !file">
            {{ submitting ? 'Importing\u2026' : 'Import' }}
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
            <span class="stat-val">{{ result.cards_created }}</span>
            <span class="stat-lbl">New Cards</span>
          </div>
          <div class="stat">
            <span class="stat-val">{{ result.skipped }}</span>
            <span class="stat-lbl">Skipped</span>
          </div>
        </div>

        <ul v-if="result.warnings?.length" class="warnings">
          <li v-for="(w, i) in result.warnings" :key="i">{{ w }}</li>
        </ul>

        <div class="actions">
          <button type="button" class="primary" @click="emit('close')">Done</button>
        </div>
      </div>
    </div>

    <LocationModal
      v-if="showLocationModal"
      :location="null"
      @created="onLocationCreated"
      @close="onLocationModalClose"
    />
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
  width: 400px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card.dimmed {
  opacity: 0.4;
  pointer-events: none;
  filter: brightness(0.7);
  transition: opacity 150ms ease, filter 150ms ease;
}
.modal-card h2 {
  font-size: 20px;
  color: var(--gold);
  margin-bottom: 18px;
}
.drop-zone {
  border: 2px dashed var(--border);
  border-radius: 6px;
  padding: 28px 16px;
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
  border-color: var(--gold-dim);
  background: rgba(201, 162, 39, 0.04);
}
.drop-zone.has-file {
  border-color: var(--gold);
  background: rgba(201, 162, 39, 0.07);
}
.drop-label {
  font-size: 13px;
  color: var(--text-dim);
}
.drop-hint {
  font-size: 11px;
  color: var(--text-faint);
}
.file-name {
  font-size: 13px;
  color: var(--text);
  font-weight: 600;
}
.file-size {
  font-size: 11px;
  color: var(--text-dim);
}
.field {
  display: block;
  margin-bottom: 14px;
}
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  margin-bottom: 5px;
}
.hint {
  text-transform: none;
  letter-spacing: 0;
  color: var(--text-faint);
  font-size: 10px;
}
.error {
  color: var(--cond-dmg);
  margin: 4px 0 12px;
  font-size: 12px;
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
.result {
  padding-top: 4px;
}
.stats {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}
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
  color: var(--gold);
  font-family: var(--font-display), serif;
}
.stat-lbl {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  margin-top: 4px;
}
.warnings {
  list-style: none;
  padding: 0;
  margin: 0 0 8px;
  max-height: 120px;
  overflow-y: auto;
  font-size: 12px;
  color: var(--text-dim);
}
.warnings li {
  padding: 3px 0;
  border-bottom: 1px solid var(--border);
}
.warnings li::before {
  content: '\26A0\FE0F ';
}
</style>
