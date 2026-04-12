<script setup>
import { reactive, ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useCollectionStore } from '../stores/collection'

const props = defineProps({
  location: { type: Object, default: null },
})
const emit = defineEmits(['close', 'created'])
const collection = useCollectionStore()

const isEdit = computed(() => !!props.location)

const form = reactive({
  type: props.location?.type || 'drawer',
  name: props.location?.name || '',
  description: props.location?.description || '',
})
const submitting = ref(false)
const error = ref('')
const confirmingDelete = ref(false)
const nameInput = ref(null)

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => nameInput.value?.focus())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

async function submit() {
  if (!form.name.trim()) {
    error.value = 'Name is required'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    const payload = {
      type: form.type,
      name: form.name.trim(),
      description: form.description.trim() || null,
    }
    if (isEdit.value) {
      await collection.updateLocation(props.location.id, payload)
    } else {
      const created = await collection.createLocation(payload)
      emit('created', created)
    }
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || `Failed to ${isEdit.value ? 'update' : 'create'} location`
  } finally {
    submitting.value = false
  }
}

async function doDelete() {
  submitting.value = true
  try {
    await collection.deleteLocation(props.location.id)
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to delete location'
  } finally {
    submitting.value = false
    confirmingDelete.value = false
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="location-modal-title"
    >
      <h2 id="location-modal-title" class="display">{{ isEdit ? 'Edit Location' : 'New Location' }}</h2>

      <form @submit.prevent="submit">
        <label class="field">
          <span class="label">Type</span>
          <div class="segmented">
            <button
              type="button"
              class="seg"
              :class="{ active: form.type === 'drawer' }"
              @click="form.type = 'drawer'"
            >
              <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/>
                <line x1="2.5" y1="9" x2="17.5" y2="9"/>
                <circle cx="10" cy="6" r="0.7" fill="currentColor"/>
                <circle cx="10" cy="13" r="0.7" fill="currentColor"/>
              </svg>
              Drawer
            </button>
            <button
              type="button"
              class="seg"
              :class="{ active: form.type === 'binder' }"
              @click="form.type = 'binder'"
            >
              <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="4" y="2.5" width="12" height="15" rx="1"/>
                <line x1="6.5" y1="2.5" x2="6.5" y2="17.5"/>
                <circle cx="6.5" cy="6" r="1" fill="currentColor"/>
                <circle cx="6.5" cy="10" r="1" fill="currentColor"/>
                <circle cx="6.5" cy="14" r="1" fill="currentColor"/>
              </svg>
              Binder
            </button>
            <button
              type="button"
              class="seg"
              :class="{ active: form.type === 'deck' }"
              @click="form.type = 'deck'"
            >
              <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="4" y="4" width="12" height="14" rx="1"/>
                <rect x="3" y="3" width="12" height="14" rx="1" opacity="0.5"/>
                <rect x="2" y="2" width="12" height="14" rx="1" opacity="0.25"/>
              </svg>
              Deck
            </button>
          </div>
        </label>

        <label class="field">
          <span class="label">Name</span>
          <input id="loc-name" ref="nameInput" v-model="form.name" type="text" maxlength="100" placeholder="e.g. Foundations Drawer" />
        </label>

        <label class="field">
          <span class="label">Description <span class="hint">(optional)</span></span>
          <textarea id="loc-description" v-model="form.description" maxlength="500" rows="3"></textarea>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button
            v-if="isEdit"
            type="button"
            class="delete-btn"
            :disabled="submitting"
            @click="confirmingDelete ? doDelete() : (confirmingDelete = true)"
          >{{ confirmingDelete ? 'Confirm Delete' : 'Delete' }}</button>
          <span class="spacer"></span>
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="submitting">
            {{ submitting ? (isEdit ? 'Saving\u2026' : 'Creating\u2026') : (isEdit ? 'Save' : 'Create') }}
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
  width: 380px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--gold);
  margin-bottom: 18px;
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
.segmented {
  display: flex;
  border: 1px solid var(--border);
  border-radius: 4px;
  overflow: hidden;
}
.seg {
  flex: 1;
  background: var(--bg-0);
  border: none;
  border-radius: 0;
  padding: 9px;
  color: var(--text-dim);
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.seg.active {
  background: var(--gold);
  color: var(--bg-0);
  font-weight: 600;
}
.error {
  color: var(--cond-dmg);
  margin: 4px 0 12px;
  font-size: 12px;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 18px;
}
.spacer {
  flex: 1;
}
.delete-btn {
  background: transparent;
  border: 1px solid var(--cond-dmg);
  color: var(--cond-dmg);
  font-size: 12px;
  padding: 7px 12px;
}
.delete-btn:hover {
  background: var(--cond-dmg);
  color: var(--bg-0);
}
</style>
