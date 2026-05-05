<script setup>
import { computed, onBeforeUnmount, onMounted, nextTick, ref } from 'vue'
import { useCollectionStore } from '../stores/collection'

const props = defineProps({
  group: { type: Object, default: null },
})
const emit = defineEmits(['close'])
const collection = useCollectionStore()

const isEdit = computed(() => !!props.group)
const name = ref(props.group?.name ?? '')
const parentGroupId = ref(props.group?.parent_group_id ?? null)
const submitting = ref(false)
const error = ref('')
const nameInput = ref(null)

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => nameInput.value?.focus())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

/**
 * Available parents = every group except the one being edited and any of
 * its descendants (avoids cycles, mirrors the server-side check). Walks
 * the recursive sidebar tree once and emits flattened entries with depth
 * so the picker can indent them.
 */
const parentChoices = computed(() => {
  const blocked = new Set()
  if (props.group?.id) {
    blocked.add(props.group.id)
    const collect = (children) => {
      for (const c of children || []) {
        if (c.kind === 'group') {
          blocked.add(c.id)
          collect(c.children)
        }
      }
    }
    const findSelf = (items) => {
      for (const item of items) {
        if (item.kind !== 'group') continue
        if (item.id === props.group.id) {
          collect(item.children)
          return true
        }
        if (findSelf(item.children || [])) return true
      }
      return false
    }
    findSelf(collection.sidebarItems)
  }

  const out = []
  const walk = (items, depth) => {
    for (const item of items) {
      if (item.kind !== 'group') continue
      if (!blocked.has(item.id)) {
        out.push({ id: item.id, name: item.name, depth })
      }
      walk(item.children || [], depth + 1)
    }
  }
  walk(collection.sidebarItems, 0)
  return out
})

async function submit() {
  if (!name.value.trim()) {
    error.value = 'Name is required'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    const payload = {
      name: name.value.trim(),
      parent_group_id: parentGroupId.value,
    }
    if (isEdit.value) {
      await collection.updateGroup(props.group.id, payload)
    } else {
      await collection.createGroup(payload)
    }
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || `Failed to ${isEdit.value ? 'update' : 'create'} group`
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true">
      <header>
        <h2>{{ isEdit ? 'Edit group' : 'New group' }}</h2>
        <button type="button" class="close-btn" @click="emit('close')">×</button>
      </header>

      <form @submit.prevent="submit">
        <label class="field">
          <span>Name</span>
          <input
            ref="nameInput"
            v-model="name"
            type="text"
            maxlength="100"
            required
            autocomplete="off"
          />
        </label>

        <label class="field">
          <span>Parent group</span>
          <select v-model="parentGroupId">
            <option :value="null">— Top level —</option>
            <option v-for="g in parentChoices" :key="g.id" :value="g.id">
              {{ '— '.repeat(g.depth) }}{{ g.name }}
            </option>
          </select>
        </label>

        <div v-if="error" class="error">{{ error }}</div>

        <footer>
          <button type="button" class="btn" @click="emit('close')" :disabled="submitting">Cancel</button>
          <button type="submit" class="btn primary" :disabled="submitting">
            {{ submitting ? 'Saving…' : (isEdit ? 'Save' : 'Create') }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md, 6px);
  width: 100%;
  max-width: 420px;
  padding: 16px 20px 20px;
  color: var(--ink-100);
}
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
header h2 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}
.close-btn {
  background: transparent;
  border: 0;
  color: var(--ink-50);
  font-size: 18px;
  cursor: pointer;
}
.close-btn:hover { color: var(--ink-100); }

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
  font-size: 12px;
}
.field span {
  color: var(--ink-50);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-size: 10px;
}
.field input,
.field select {
  background: var(--bg-0);
  color: var(--ink-100);
  border: 1px solid var(--hairline);
  border-radius: 3px;
  padding: 7px 10px;
  font-size: 13px;
  outline: none;
}
.field input:focus,
.field select:focus {
  border-color: var(--amber);
}
.error {
  color: #d46a6a;
  font-size: 12px;
  margin-bottom: 8px;
}
footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}
.btn {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  padding: 7px 14px;
  font-size: 12px;
  border-radius: 3px;
  cursor: pointer;
}
.btn:hover { color: var(--ink-100); border-color: var(--ink-30); }
.btn.primary {
  background: var(--amber);
  color: #1a1408;
  border-color: var(--amber);
  font-weight: 600;
}
.btn.primary:hover {
  background: color-mix(in oklab, var(--amber) 85%, white);
}
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
