<script setup>
import { reactive, ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../lib/api'
import { useCollectionStore } from '../stores/collection'
import { confirm } from '../composables/useConfirm'

const props = defineProps({
  location: { type: Object, default: null },
})
const emit = defineEmits(['close', 'created'])
const collection = useCollectionStore()
const router = useRouter()

const isEditLocation = computed(() => !!props.location && props.location.kind !== 'deck')
const isEditDeck     = computed(() => props.location?.kind === 'deck')
const isEdit         = computed(() => isEditLocation.value || isEditDeck.value)

const activeType = ref(
  props.location?.kind === 'deck' ? 'deck' : (props.location?.type || 'drawer'),
)

const drawerForm = reactive({
  name: isEditLocation.value && props.location.type === 'drawer' ? props.location.name : '',
  description: isEditLocation.value && props.location.type === 'drawer' ? (props.location.description || '') : '',
})
const binderForm = reactive({
  name: isEditLocation.value && props.location.type === 'binder' ? props.location.name : '',
  description: isEditLocation.value && props.location.type === 'binder' ? (props.location.description || '') : '',
})
const deckForm = reactive({
  name: isEditDeck.value ? props.location.name : '',
  description: isEditDeck.value ? (props.location.description || '') : '',
  format: isEditDeck.value ? props.location.format : 'commander',
  commander_1_scryfall_id: isEditDeck.value ? (props.location.commander1?.scryfall_id ?? null) : null,
  commander_2_scryfall_id: isEditDeck.value ? (props.location.commander2?.scryfall_id ?? null) : null,
  companion_scryfall_id:   isEditDeck.value ? (props.location.companion?.scryfall_id ?? null) : null,
})
const commander1Name = ref(isEditDeck.value ? (props.location.commander1?.name ?? '') : '')
const commander2Name = ref(isEditDeck.value ? (props.location.commander2?.name ?? '') : '')
const companionName  = ref(isEditDeck.value ? (props.location.companion?.name ?? '') : '')

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

async function submit() {
  const form = activeType.value === 'drawer' ? drawerForm
             : activeType.value === 'binder' ? binderForm
             : deckForm
  if (!form.name.trim()) {
    error.value = 'Name is required'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    if (activeType.value === 'deck') {
      const payload = {
        name: deckForm.name.trim(),
        format: deckForm.format,
        description: deckForm.description.trim() || null,
        commander_1_scryfall_id: deckForm.commander_1_scryfall_id,
        commander_2_scryfall_id: deckForm.commander_2_scryfall_id,
        companion_scryfall_id: deckForm.companion_scryfall_id,
      }
      if (isEditDeck.value) {
        await collection.updateDeck(props.location.id, payload)
        emit('close')
      } else {
        const deck = await collection.createDeck(payload)
        emit('created', deck)
        emit('close')
        router.push({ name: 'deck', params: { id: deck.id } })
      }
    } else {
      const payload = {
        type: activeType.value,
        name: form.name.trim(),
        description: form.description.trim() || null,
      }
      if (isEditLocation.value) {
        await collection.updateLocation(props.location.id, payload)
      } else {
        const created = await collection.createLocation(payload)
        emit('created', created)
      }
      emit('close')
    }
  } catch (e) {
    error.value = e.response?.data?.message || `Failed to ${isEdit.value ? 'update' : 'create'}`
  } finally {
    submitting.value = false
  }
}

async function doDelete() {
  const ok = await confirm({
    title: isEditDeck.value ? 'Delete deck?' : 'Delete location?',
    message: `Remove "${props.location.name}" permanently?`,
    confirmText: 'Delete',
    destructive: true,
  })
  if (!ok) return
  submitting.value = true
  try {
    if (isEditDeck.value) {
      await collection.deleteDeck(props.location.id)
    } else {
      await collection.deleteLocation(props.location.id)
    }
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to delete'
  } finally {
    submitting.value = false
  }
}

// Commander / companion autocomplete
const cmdr1Results = ref([])
const cmdr2Results = ref([])
const compResults  = ref([])
let timer1, timer2, timer3

function searchCards(query, target, extra = '') {
  clearTimeout(target === cmdr1Results ? timer1 : target === cmdr2Results ? timer2 : timer3)
  const q = (query || '').trim()
  if (!q) { target.value = []; return }
  const fullQ = extra ? `${q} ${extra}` : q
  const t = setTimeout(async () => {
    try {
      const { data } = await api.get('/scryfall-cards/search', {
        params: { q: fullQ, per_page: 10 },
      })
      target.value = data.data || data.results || data
    } catch {
      target.value = []
    }
  }, 200)
  if (target === cmdr1Results) timer1 = t
  else if (target === cmdr2Results) timer2 = t
  else timer3 = t
}

function pickCommander(slot, card) {
  if (slot === 1) {
    deckForm.commander_1_scryfall_id = card.scryfall_id
    commander1Name.value = card.name
    cmdr1Results.value = []
  } else {
    deckForm.commander_2_scryfall_id = card.scryfall_id
    commander2Name.value = card.name
    cmdr2Results.value = []
  }
}

function pickCompanion(card) {
  deckForm.companion_scryfall_id = card.scryfall_id
  companionName.value = card.name
  compResults.value = []
}

watch(commander1Name, (v) => {
  const extra = deckForm.format === 'oathbreaker' ? 't:planeswalker' : 't:legendary'
  searchCards(v, cmdr1Results, extra)
})
watch(commander2Name, (v) => {
  searchCards(v, cmdr2Results, 't:legendary')
})
watch(companionName, (v) => {
  searchCards(v, compResults, 'keyword:Companion')
})

const showCommanderSlots = computed(() =>
  ['commander', 'oathbreaker'].includes(deckForm.format),
)
const showPartnerSlot = computed(() => deckForm.format === 'commander')
const showCompanion   = computed(() => deckForm.format === 'commander')
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="location-modal-title"
    >
      <h2 id="location-modal-title" class="display">
        {{ isEditDeck ? 'Edit Deck' : isEditLocation ? 'Edit Location' : 'New Location' }}
      </h2>

      <form @submit.prevent="submit">
        <label v-if="!isEdit" class="field">
          <span class="label">Type</span>
          <div class="segmented">
            <button type="button" class="seg" :class="{ active: activeType === 'drawer' }" @click="activeType = 'drawer'">Drawer</button>
            <button type="button" class="seg" :class="{ active: activeType === 'binder' }" @click="activeType = 'binder'">Binder</button>
            <button type="button" class="seg" :class="{ active: activeType === 'deck' }"   @click="activeType = 'deck'">Deck</button>
          </div>
        </label>

        <!-- Shared name + description -->
        <label class="field">
          <span class="label">Name</span>
          <input
            v-if="activeType === 'drawer'"
            ref="nameInput"
            v-model="drawerForm.name"
            type="text"
            maxlength="100"
          />
          <input
            v-else-if="activeType === 'binder'"
            ref="nameInput"
            v-model="binderForm.name"
            type="text"
            maxlength="100"
          />
          <input
            v-else
            ref="nameInput"
            v-model="deckForm.name"
            type="text"
            maxlength="100"
          />
        </label>

        <label class="field">
          <span class="label">Description <span class="hint">(optional)</span></span>
          <textarea
            v-if="activeType === 'drawer'"
            v-model="drawerForm.description"
            maxlength="500"
            rows="3"
          ></textarea>
          <textarea
            v-else-if="activeType === 'binder'"
            v-model="binderForm.description"
            maxlength="500"
            rows="3"
          ></textarea>
          <textarea
            v-else
            v-model="deckForm.description"
            maxlength="500"
            rows="3"
          ></textarea>
        </label>

        <!-- Deck-only fields -->
        <template v-if="activeType === 'deck'">
          <label class="field">
            <span class="label">Format</span>
            <select v-model="deckForm.format">
              <option value="commander">Commander</option>
              <option value="oathbreaker">Oathbreaker</option>
              <option value="pauper">Pauper</option>
              <option value="standard">Standard</option>
              <option value="modern">Modern</option>
            </select>
          </label>

          <div v-if="showCommanderSlots" class="field autocomplete-field">
            <span class="label">{{ deckForm.format === 'oathbreaker' ? 'Oathbreaker' : 'Commander' }}</span>
            <input type="text" v-model="commander1Name" placeholder="Search…" />
            <ul v-if="cmdr1Results.length" class="autocomplete-list">
              <li v-for="c in cmdr1Results" :key="c.scryfall_id" @mousedown.prevent="pickCommander(1, c)">
                {{ c.name }} · {{ c.set_code?.toUpperCase() }}
              </li>
            </ul>
          </div>

          <div v-if="showPartnerSlot" class="field autocomplete-field">
            <span class="label">Partner (optional)</span>
            <input type="text" v-model="commander2Name" placeholder="Search…" />
            <ul v-if="cmdr2Results.length" class="autocomplete-list">
              <li v-for="c in cmdr2Results" :key="c.scryfall_id" @mousedown.prevent="pickCommander(2, c)">
                {{ c.name }} · {{ c.set_code?.toUpperCase() }}
              </li>
            </ul>
          </div>

          <div v-if="showCompanion" class="field autocomplete-field">
            <span class="label">Companion (optional)</span>
            <input type="text" v-model="companionName" placeholder="Search…" />
            <ul v-if="compResults.length" class="autocomplete-list">
              <li v-for="c in compResults" :key="c.scryfall_id" @mousedown.prevent="pickCompanion(c)">
                {{ c.name }} · {{ c.set_code?.toUpperCase() }}
              </li>
            </ul>
          </div>
        </template>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button
            v-if="isEdit"
            type="button"
            class="delete-btn"
            :disabled="submitting"
            @click="doDelete"
          >Delete</button>
          <span class="spacer"></span>
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="submitting">
            {{ submitting ? (isEdit ? 'Saving…' : 'Creating…') : (isEdit ? 'Save' : 'Create') }}
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
  width: 420px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--gold);
  margin-bottom: 18px;
}
.field { display: block; margin-bottom: 14px; position: relative; }
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  margin-bottom: 5px;
}
.hint { text-transform: none; letter-spacing: 0; color: var(--text-faint); font-size: 10px; }
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
  padding: 9px;
  color: var(--text-dim);
  font-size: 13px;
}
.seg.active {
  background: var(--gold);
  color: var(--bg-0);
  font-weight: 600;
}
.autocomplete-list {
  position: absolute;
  left: 0;
  right: 0;
  top: 100%;
  background: var(--bg-0);
  border: 1px solid var(--border);
  border-radius: 4px;
  margin: 2px 0 0;
  padding: 0;
  list-style: none;
  max-height: 180px;
  overflow-y: auto;
  z-index: 10;
}
.autocomplete-list li {
  padding: 0.4rem 0.6rem;
  font-size: 0.82rem;
  cursor: pointer;
}
.autocomplete-list li:hover { background: var(--bg-1); }
.error { color: var(--cond-dmg); margin: 4px 0 12px; font-size: 12px; }
.actions { display: flex; align-items: center; gap: 8px; margin-top: 18px; }
.spacer { flex: 1; }
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
