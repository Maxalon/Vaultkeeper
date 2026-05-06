<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useToast } from '../../composables/useToast'
import PrintingPickerModal from '../PrintingPickerModal.vue'
import QuantityStepper from './QuantityStepper.vue'

/**
 * Edit the physical copy bound to a single deck slot. Powers the
 * Physical Copies tab's per-row "Edit…" action.
 *
 * For stacks (entry.quantity > 1) the modal exposes an "Apply to N of K"
 * stepper. When N < K the backend splits the source CE + deck_entry in
 * two and writes the patch onto the new sibling; when N == K it patches
 * the bound CE in place.
 */
const props = defineProps({
  /** Deck-bound DeckEntry presentation row (id, quantity, scryfall_id, ...). */
  entry: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const deck = useDeckStore()
const toast = useToast()

const CONDITIONS = ['NM', 'LP', 'MP', 'HP', 'DMG']

const physical = computed(() => props.entry.physical_copy || {})
const card     = computed(() => props.entry.scryfall_card || {})

const condition = ref(physical.value.condition || 'NM')
// Tri-state finish: nonfoil / foil / etched. Mutually exclusive — the
// backend forces foil=false when is_etched=true (and vice versa via UI).
const initialFinish = physical.value.is_etched
  ? 'etched'
  : (physical.value.foil ? 'foil' : 'nonfoil')
const finish = ref(initialFinish)
const notes  = ref(physical.value.notes || '')

// Printing — null means "no change". Stores a scryfall_id when picked.
const newPrintingId = ref(null)
const printingOpen  = ref(false)

const totalQuantity = computed(() => Number(props.entry.quantity) || 1)
const applyTo = ref(totalQuantity.value)

const submitting = ref(false)

const printingChanged = computed(
  () => newPrintingId.value !== null && newPrintingId.value !== props.entry.scryfall_id,
)
const conditionChanged = computed(() => condition.value !== (physical.value.condition || 'NM'))
const finishChanged    = computed(() => finish.value !== initialFinish)
const notesChanged     = computed(() => (notes.value || '') !== (physical.value.notes || ''))
const anyChange = computed(
  () => printingChanged.value || conditionChanged.value || finishChanged.value || notesChanged.value,
)
const canSubmit = computed(() => anyChange.value && !submitting.value)

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  try {
    const payload = { apply_to: applyTo.value }
    if (physical.value.version != null) payload.version = physical.value.version
    if (conditionChanged.value) payload.condition = condition.value
    if (finishChanged.value) {
      payload.foil      = finish.value === 'foil'
      payload.is_etched = finish.value === 'etched'
    }
    if (notesChanged.value)     payload.notes     = notes.value || null
    if (printingChanged.value)  payload.scryfall_id = newPrintingId.value

    await deck.editPhysicalCopy(deck.deck.id, props.entry.id, payload)
    toast.success(applyTo.value < totalQuantity.value ? 'Copies split.' : 'Copy updated.')
    emit('close')
  } catch (e) {
    // Store-level toast already surfaced the error.
  } finally {
    submitting.value = false
  }
}

async function unbind() {
  if (submitting.value) return
  // Confirm before sending the whole slot to review — this can't easily
  // be undone in one click.
  if (!window.confirm(
    `Unbind ${totalQuantity.value} ${cardName.value} ${totalQuantity.value === 1 ? 'copy' : 'copies'} and queue for review?`
  )) return
  submitting.value = true
  try {
    await deck.removeEntry(deck.deck.id, props.entry.id)
    emit('close')
  } catch (e) {
    // toast already surfaced
  } finally {
    submitting.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !submitting.value && !printingOpen.value) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const cardName = computed(() => card.value.name || 'card')

const previewPrintingId = computed(
  () => newPrintingId.value || props.entry.scryfall_id,
)
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="edit-copy-title"
    >
      <h2 id="edit-copy-title" class="display">Edit copy of {{ cardName }}</h2>
      <p class="subtitle">
        Set this physical copy's condition, foil, notes, or printing.
        Stacks can split — pick how many copies the change applies to.
      </p>

      <div class="grid">
        <label class="field">
          <span class="label">Condition</span>
          <select v-model="condition" name="edit-copy-condition">
            <option v-for="c in CONDITIONS" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>

        <label class="field">
          <span class="label">Finish</span>
          <select v-model="finish" name="edit-copy-finish">
            <option value="nonfoil">Nonfoil</option>
            <option value="foil">Foil</option>
            <option value="etched">Etched</option>
          </select>
        </label>

        <div class="field full">
          <span class="label">Notes</span>
          <textarea
            v-model="notes"
            name="edit-copy-notes"
            rows="2"
            maxlength="1000"
            placeholder="e.g. signed, crimped, alter…"
          />
        </div>

        <div class="field full">
          <span class="label">Printing</span>
          <button
            type="button"
            class="printing-btn"
            @click="printingOpen = true"
          >
            {{ printingChanged
              ? 'Pick again — new printing chosen'
              : 'Browse printings…' }}
          </button>
        </div>

        <div v-if="totalQuantity > 1" class="field full">
          <span class="label">Apply to</span>
          <div class="apply-row">
            <QuantityStepper
              :value="applyTo"
              :min="1"
              :inc-disabled="applyTo >= totalQuantity"
              @inc="applyTo = Math.min(totalQuantity, applyTo + 1)"
              @dec="applyTo = Math.max(1, applyTo - 1)"
            />
            <span class="apply-of">of {{ totalQuantity }}</span>
            <span v-if="applyTo < totalQuantity" class="apply-hint">
              splits the slot
            </span>
          </div>
        </div>
      </div>

      <div class="actions">
        <button
          type="button"
          class="danger"
          :disabled="submitting"
          @click="unbind"
        >Unbind → review</button>
        <span class="spacer" />
        <button type="button" :disabled="submitting" @click="emit('close')">Cancel</button>
        <button
          type="button"
          class="primary"
          :disabled="!canSubmit"
          @click="submit"
        >{{ submitting ? 'Saving…' : 'Save' }}</button>
      </div>
    </div>

    <PrintingPickerModal
      :open="printingOpen"
      :oracle-id="card.oracle_id || null"
      :card-name="cardName"
      :selected-printing-id="previewPrintingId"
      @update:open="printingOpen = $event"
      @select="(id) => { newPrintingId = id; printingOpen = false }"
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
  border: 1px solid var(--hairline);
  border-top: 2px solid var(--amber);
  border-radius: 6px;
  width: 520px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 20px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--amber);
  margin: 0 0 4px;
}
.subtitle {
  margin: 0 0 16px;
  font-size: 12px;
  color: var(--ink-50);
}
.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 14px;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.field.full { grid-column: 1 / -1; }
.field.foil {
  flex-direction: row;
  align-items: center;
  gap: 8px;
}
.field.foil .label { margin-right: auto; }
.label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
}
select, textarea, .printing-btn {
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  color: var(--ink-100);
  padding: 6px 8px;
  font: inherit;
  font-size: 13px;
}
textarea {
  resize: vertical;
  min-height: 38px;
}
select:focus, textarea:focus, .printing-btn:focus {
  outline: none;
  border-color: var(--amber);
}
.printing-btn {
  cursor: pointer;
  text-align: left;
}
.printing-btn:hover { background: var(--bg-2); }
.apply-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.apply-of {
  font-size: 12px;
  color: var(--ink-70);
}
.apply-hint {
  font-size: 11px;
  color: var(--amber);
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 18px;
}
.actions .spacer { flex: 1; }
.actions button {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-100);
  padding: 6px 14px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
}
.actions button:hover:not(:disabled) {
  background: var(--bg-2);
}
.actions button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.actions .primary {
  background: var(--amber);
  color: #1a120c;
  border-color: var(--amber);
}
.actions .primary:hover:not(:disabled) {
  background: var(--amber-hi);
}
.actions .danger {
  color: var(--cond-dmg, #d46a6a);
  border-color: var(--cond-dmg, #d46a6a);
}
.actions .danger:hover:not(:disabled) {
  background: rgba(212, 106, 106, 0.12);
}
</style>
