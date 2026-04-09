<script setup>
import { reactive, ref } from 'vue'
import { useCollectionStore } from '../stores/collection'

const emit = defineEmits(['close'])
const collection = useCollectionStore()

const form = reactive({
  type: 'drawer',
  name: '',
  set_code: '',
  description: '',
})
const submitting = ref(false)
const error = ref('')

async function submit() {
  if (!form.name.trim()) {
    error.value = 'Name is required'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    await collection.createLocation({
      type: form.type,
      name: form.name.trim(),
      set_code: form.set_code.trim() || null,
      description: form.description.trim() || null,
    })
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to create location'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div class="modal-card">
      <h2 class="display">New Location</h2>

      <form @submit.prevent="submit">
        <label class="field">
          <span class="label">Type</span>
          <div class="segmented">
            <button
              type="button"
              class="seg"
              :class="{ active: form.type === 'drawer' }"
              @click="form.type = 'drawer'"
            >Drawer</button>
            <button
              type="button"
              class="seg"
              :class="{ active: form.type === 'binder' }"
              @click="form.type = 'binder'"
            >Binder</button>
          </div>
        </label>

        <label class="field">
          <span class="label">Name</span>
          <input v-model="form.name" type="text" maxlength="100" placeholder="e.g. Foundations Drawer" />
        </label>

        <label class="field">
          <span class="label">Set Code <span class="hint">(optional)</span></span>
          <input v-model="form.set_code" type="text" maxlength="10" placeholder="e.g. FDN" />
        </label>

        <label class="field">
          <span class="label">Description <span class="hint">(optional)</span></span>
          <textarea v-model="form.description" maxlength="500" rows="3"></textarea>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="submitting">
            {{ submitting ? 'Creating…' : 'Create' }}
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
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
</style>
