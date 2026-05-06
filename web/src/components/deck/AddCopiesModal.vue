<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '../../lib/api'
import { useDeckStore } from '../../stores/deck'
import { useToast } from '../../composables/useToast'
import HelpHint from '../HelpHint.vue'
import QuantityStepper from './QuantityStepper.vue'

const props = defineProps({
  /** { id, name } — the deck the slot belongs to. */
  deck:    { type: Object, required: true },
  /** Bound deck_entry for this (scryfall_id, zone), or null. */
  bound:   { type: Object, default: null },
  /** Wanted-only deck_entry sibling, or null. */
  wanted:  { type: Object, default: null },
  /** Card metadata — used when neither sibling carries scryfall_card. */
  card:    { type: Object, required: true },
  /** scryfall_id + zone — drives source dropdown + new-slot creation. */
  scryfallId: { type: String, required: true },
  zone:       { type: String, required: true },
  /** category to apply when minting a brand-new bound entry. */
  category:   { type: String, default: null },
})
const emit = defineEmits(['close'])

const store = useDeckStore()
const toast = useToast()

// Per-copy rows. Each row is the user's choice of where a copy comes
// from: 'new' = mint a fresh CE in this deck's storage (NM/non-foil
// like the Assemble Deck button), or a CollectionEntry id from the
// user's collection.
const rows = ref([{ source: 'new' }])
const count = computed({
  get: () => rows.value.length,
  set: (n) => {
    const want = Math.max(1, Math.min(99, Number(n) || 1))
    if (want > rows.value.length) {
      while (rows.value.length < want) rows.value.push({ source: 'new' })
    } else if (want < rows.value.length) {
      rows.value.splice(want)
    }
  },
})

const currentBoundQty  = computed(() => props.bound?.quantity  ?? 0)
const currentWantedQty = computed(() => props.wanted?.quantity ?? 0)

// Default wantedAfter shrinks the wishlist by the number of copies
// the user is adding (the natural "I bought what I wanted" case). The
// user can bump it back up if they also want to keep more on the list.
const wantedAfter = ref(Math.max(0, currentWantedQty.value - rows.value.length))
watch(() => rows.value.length, (n) => {
  wantedAfter.value = Math.max(0, currentWantedQty.value - n)
})

// Owned copies of this card across the user's collection — used to
// populate each row's source dropdown.
const ownedCopies = computed(
  () => store.ownedCopiesByScryfallId[props.scryfallId] || [],
)
onMounted(() => {
  store.loadOwnedCopies(props.scryfallId)
})

function copyLabel(copy) {
  const where = copy.location_name || 'Unassigned'
  const foil = copy.foil ? ' · F' : ''
  return `${where} · qty ${copy.quantity}${foil}`
}

const submitting = ref(false)

// Breakdown derived from rows: how many "new" + how many of each
// existing CE id.
const breakdown = computed(() => {
  let newCount = 0
  const ext = new Map()
  for (const r of rows.value) {
    if (r.source === 'new') newCount += 1
    else ext.set(r.source, (ext.get(r.source) || 0) + 1)
  }
  return { newCount, ext }
})

// Validation rules — see AddCopiesModal plan §4.
//   1. Multiple distinct external CEs in one submit are not supported
//      (a deck_entry has a single physical_copy_id; mixing rebinds
//      and routes the previous CE to review).
//   2. If the slot is already bound, only "create new" rows are
//      supported — extending a bound slot from a different external
//      CE would orphan the existing deck-location CE.
const validation = computed(() => {
  const { newCount, ext } = breakdown.value
  if (ext.size > 1) {
    return { ok: false, message: 'Pick from one existing copy at a time. Run the dialog again to add a second source.' }
  }
  if (props.bound && ext.size > 0) {
    return { ok: false, message: 'This card is already bound to a copy in this deck. Pick "Create new" here, or first mark the existing copy as sold from the menu.' }
  }
  if (newCount + ext.size === 0 && wantedAfter.value === currentWantedQty.value) {
    return { ok: false, message: null }
  }
  return { ok: true, message: null }
})

const canSubmit = computed(() => validation.value.ok && !submitting.value)

// Sequence the underlying API calls. The order matters:
//   - When binding an existing CE for a previously-unbound slot, we
//     POST a wanted-only entry first, then PATCH it to bind. PATCH's
//     bindPhysicalCopy() moves/splits the source CE into the
//     deck-location atomically.
//   - "Create new" copies are added LAST, via mode=create_new_copy
//     on the now-bound slot, so growWithNewCopy() bumps the bound CE
//     in the deck-location instead of minting a separate one.
async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  try {
    const { newCount, ext } = breakdown.value
    const deckId = props.deck.id

    // Step 1 — owned-side mutations.
    let boundEntry = props.bound

    if (ext.size === 1) {
      const [ceId, extCount] = [...ext.entries()][0]
      if (!boundEntry) {
        // No existing bound slot — mint a wanted-only entry, then
        // PATCH it to bind to the chosen external CE.
        const created = await store.addEntry(deckId, {
          scryfall_id: props.scryfallId,
          zone:        props.zone,
          quantity:    extCount,
          wanted:      props.zone,
          category:    props.category,
        })
        const bound = await store.updateEntry(deckId, created.id, {
          physical_copy_id: ceId,
          quantity:         extCount,
          wanted:           null,
        })
        boundEntry = bound || created
      } else {
        // Bound slot + external CE: blocked by validation; this
        // branch is unreachable.
      }
    }

    if (newCount > 0) {
      if (boundEntry) {
        await store.growEntryAsNewCopy(
          deckId,
          boundEntry.id,
          (boundEntry.quantity || 0) + newCount,
        )
      } else {
        // Pure "create new" with no prior slot — POST mode=create_new_copy
        // creates the bound deck_entry + deck-location CE in one shot.
        const { data } = await api.post(`/decks/${deckId}/entries`, {
          scryfall_id: props.scryfallId,
          zone:        props.zone,
          quantity:    newCount,
          category:    props.category,
          mode:        'create_new_copy',
        })
        const idx = store.entries.findIndex((e) => e.id === data.id)
        if (idx === -1) store.entries.push(data)
        else store.entries[idx] = { ...store.entries[idx], ...data }
      }
    }

    // Step 2 — wanted reconciliation. Read the current wanted entry
    // FRESH from the store (loadEntries may have mutated it during
    // step 1).
    const freshWanted = store.entries.find((e) =>
      e.scryfall_id === props.scryfallId
      && e.zone === props.zone
      && e.physical_copy_id == null
      && e.wanted != null,
    ) || null
    const freshWantedQty = freshWanted?.quantity || 0
    const delta = wantedAfter.value - freshWantedQty

    if (delta > 0) {
      await store.growWanted(deckId, props.scryfallId, props.zone, {
        delta,
        category: props.category,
      })
    } else if (delta < 0 && freshWanted) {
      const newQty = freshWantedQty + delta
      if (newQty <= 0) {
        await store.removeEntry(deckId, freshWanted.id)
      } else {
        await store.updateEntry(deckId, freshWanted.id, { quantity: newQty })
      }
    }

    toast.success('Copies added.')
    emit('close')
  } catch (e) {
    // Store-level toasts already surfaced the error; nothing more.
  } finally {
    submitting.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !submitting.value) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const cardName = computed(() => props.card?.name || 'card')
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="add-copies-title"
    >
      <h2 id="add-copies-title" class="display">Add copies of {{ cardName }}</h2>
      <p class="subtitle">Log new physical copies into this deck and adjust the wishlist in one go.</p>

      <div class="section">
        <div class="label-row">
          <span class="label">How many copies are you adding?</span>
          <HelpHint text="Pick how many physical copies you're putting into this deck right now. The list below grows so you can pick a source for each one." />
        </div>
        <input
          type="number"
          class="count-input"
          min="1"
          max="99"
          :value="count"
          @input="count = $event.target.value"
          name="add-copies-count"
        />
      </div>

      <div class="section">
        <div class="label-row">
          <span class="label">Pick a source for each copy</span>
          <HelpHint text="For each copy choose 'Create a new copy' to log a brand-new card in this deck's storage (NM, non-foil — like the Assemble Deck button) or pick an existing copy from your collection to move into this deck. Only one existing copy per dialog is supported." />
        </div>
        <ul class="rows">
          <li v-for="(row, idx) in rows" :key="idx" class="row">
            <span class="row-num">#{{ idx + 1 }}</span>
            <select
              class="row-source"
              v-model="row.source"
              :name="`add-copies-source-${idx}`"
            >
              <option value="new">Create a new copy</option>
              <option
                v-for="copy in ownedCopies"
                :key="copy.id"
                :value="copy.id"
              >{{ copyLabel(copy) }}</option>
            </select>
          </li>
        </ul>
      </div>

      <div class="section">
        <div class="label-row">
          <span class="label">Wanted afterwards</span>
          <HelpHint text="How many of this card you still want for this deck after this change. Adding owned copies normally reduces wanted by the same amount — bump this back up if you want to keep room on your wishlist." />
        </div>
        <div class="wanted-row">
          <QuantityStepper
            :value="wantedAfter"
            :min="0"
            @inc="wantedAfter += 1"
            @dec="wantedAfter = Math.max(0, wantedAfter - 1)"
          />
          <span class="wanted-was" v-if="wantedAfter !== currentWantedQty">
            (was {{ currentWantedQty }})
          </span>
        </div>
      </div>

      <p v-if="validation.message" class="validation-error">
        {{ validation.message }}
      </p>

      <div class="actions">
        <button type="button" @click="emit('close')" :disabled="submitting">Cancel</button>
        <button
          type="button"
          class="primary"
          @click="submit"
          :disabled="!canSubmit"
        >{{ submitting ? 'Adding…' : 'Add copies' }}</button>
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
  width: 540px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--amber);
  margin-bottom: 4px;
}
.subtitle {
  margin: 0 0 18px;
  font-size: 12px;
  color: var(--ink-50);
}
.section { margin-bottom: 18px; }
.section:last-of-type { margin-bottom: 8px; }
.label-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}
.label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
}
.count-input {
  width: 80px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  color: var(--ink-100);
  padding: 6px 10px;
  font-size: 13px;
}
.count-input:focus {
  outline: none;
  border-color: var(--amber);
}
.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 240px;
  overflow-y: auto;
}
.row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.row-num {
  font-size: 11px;
  color: var(--ink-50);
  width: 24px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.row-source {
  flex: 1;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  color: var(--ink-100);
  padding: 6px 8px;
  font-size: 13px;
}
.row-source:focus {
  outline: none;
  border-color: var(--amber);
}
.wanted-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.wanted-was {
  font-size: 11px;
  color: var(--ink-50);
}
.validation-error {
  margin: 0 0 12px;
  font-size: 12px;
  color: var(--cond-dmg, #d46a6a);
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 22px;
}
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
</style>
