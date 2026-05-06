<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../lib/api'
import { useDeckStore } from '../stores/deck'
import { useToast } from '../composables/useToast'
import Checkbox from './Checkbox.vue'

const props = defineProps({
  /** { id, name } — minimum needed to title the modal + POST to it. */
  deck: { type: Object, required: true },
  /**
   * Optional. When omitted, the modal fetches the deck's entries on
   * mount. The deckbuilder hands them in directly (it already has
   * them); the import flow doesn't, so it lets the modal fetch.
   */
  entries: { type: Array, default: null },
})
const emit = defineEmits(['close', 'assembled'])

const store = useDeckStore()
const toast = useToast()
const router = useRouter()

// When the parent doesn't supply entries, fetch on mount so the
// exclude autocomplete has something to search over.
const fetchedEntries = ref([])
const loadingEntries = ref(false)
const entriesList = computed(() => props.entries ?? fetchedEntries.value)

// Per-zone toggles, only surfaced when the deck has more than one zone.
const sectionsState = ref({ main: true, side: true, maybe: true })

// Exclude rows: { scryfall_id, zone, qty, name, is_commander, slotQty }.
// Stored locally because the modal only POSTs the intent on Confirm —
// nothing persists until then.
const excludes = ref([])

// Autocomplete state.
const search = ref('')
const searchOpen = ref(false)

const submitting = ref(false)

// Sections actually present in the imported list. Only these render
// as sub-toggles + filter the autocomplete; absent sections are
// silently dropped from the intent.
const presentSections = computed(() => {
  const present = new Set()
  for (const e of entriesList.value) {
    if (e.zone === 'main' || e.zone === 'side' || e.zone === 'maybe') {
      present.add(e.zone)
    }
  }
  return ['main', 'side', 'maybe'].filter((z) => present.has(z))
})

function sectionActive(zone) {
  // Single-zone decks have no UI toggle and are always fully active.
  if (presentSections.value.length <= 1) return true
  return !!sectionsState.value[zone]
}

const activeSections = computed(() =>
  presentSections.value.filter((z) => sectionActive(z)),
)

const showZoneToggles = computed(() => presentSections.value.length > 1)

// Pool the autocomplete searches over: every entry in an active
// section, dedup'd to one chip-suggestion per (scryfall_id, zone).
const candidates = computed(() => {
  const pool = []
  for (const e of entriesList.value) {
    if (!activeSections.value.includes(e.zone)) continue
    const name = e.scryfall_card?.name || ''
    if (!name) continue
    pool.push({
      scryfall_id: e.scryfall_id,
      zone: e.zone,
      name,
      is_commander: !!e.is_commander,
      is_signature_spell: !!e.is_signature_spell,
      // Slot quantity for the count picker. When two split rows exist
      // for the same (scryfall_id, zone), surface the SUM so the user
      // can exclude up to the full owned + wanted quantity.
      slotQty: e.quantity || 0,
    })
  }
  // Coalesce duplicates by (scryfall_id, zone), summing slotQty.
  const byKey = new Map()
  for (const row of pool) {
    const key = `${row.scryfall_id}|${row.zone}`
    if (byKey.has(key)) {
      byKey.get(key).slotQty += row.slotQty
    } else {
      byKey.set(key, { ...row })
    }
  }
  return [...byKey.values()].sort((a, b) => a.name.localeCompare(b.name))
})

const filteredCandidates = computed(() => {
  const q = search.value.trim().toLowerCase()
  const already = new Set(excludes.value.map((x) => `${x.scryfall_id}|${x.zone}`))
  return candidates.value
    .filter((c) => !already.has(`${c.scryfall_id}|${c.zone}`))
    .filter((c) => !q || c.name.toLowerCase().includes(q))
    .slice(0, 8)
})

function addExclude(c) {
  excludes.value.push({
    scryfall_id: c.scryfall_id,
    zone: c.zone,
    name: c.name,
    slotQty: c.slotQty,
    is_commander: c.is_commander,
    is_signature_spell: c.is_signature_spell,
    // Default to 1; commanders/sigs jump straight to slot quantity
    // (the count picker is hidden for them — the only sensible
    // exclude is "all of it").
    qty: c.is_commander || c.is_signature_spell ? c.slotQty : 1,
  })
  search.value = ''
  searchOpen.value = false
}

function removeExclude(idx) {
  excludes.value.splice(idx, 1)
}

// Defer the close so a click on a search-menu item registers as
// mousedown→addExclude before the blur tears the menu down.
function onSearchBlur() {
  setTimeout(() => { searchOpen.value = false }, 120)
}

function clampExcludeQty(idx) {
  const row = excludes.value[idx]
  if (!row) return
  const max = Math.max(1, row.slotQty)
  if (row.qty > max) row.qty = max
  if (row.qty < 1) row.qty = 1
}

const intent = computed(() => {
  const everyZoneActive =
    activeSections.value.length === presentSections.value.length
  const payload = { all: everyZoneActive }
  if (!everyZoneActive) {
    payload.sections = activeSections.value
  }
  if (excludes.value.length > 0) {
    payload.excludes = excludes.value.map((e) => ({
      scryfall_id: e.scryfall_id,
      zone: e.zone,
      qty: Math.max(1, Math.min(e.qty, e.slotQty)),
    }))
  }
  return payload
})

const canSubmit = computed(() => {
  if (submitting.value) return false
  return activeSections.value.length > 0
})

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  try {
    const result = await store.assembleDeck(props.deck.id, intent.value)
    const created = result?.created_ces ?? 0
    const wanted  = result?.slots_marked_wanted ?? 0
    let message = `Deck marked as assembled. Created ${created} cop${created === 1 ? 'y' : 'ies'} in 'Deck: ${props.deck.name}'.`
    if (wanted > 0) {
      message += ` ${wanted} slot${wanted === 1 ? '' : 's'} remain on your wishlist.`
    }
    if (created > 0) {
      // Created CEs land with review_reason = default_values_applied;
      // nudge the user to confirm or correct condition / foil / notes.
      message += ' Defaults applied (NM, non-foil) — review when ready.'
      toast.withActions(message, [
        {
          label: 'Review now',
          kind: 'primary',
          run: () => router.push({
            name: 'review',
            query: { reason: 'default_values_applied' },
          }),
        },
      ])
    } else {
      toast.success(message)
    }
    emit('assembled', result)
    emit('close')
  } finally {
    submitting.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !submitting.value) emit('close')
}

async function loadEntriesIfNeeded() {
  if (props.entries !== null) return
  loadingEntries.value = true
  try {
    const { data } = await api.get(`/decks/${props.deck.id}/entries`, {
      params: { sort: 'name' },
    })
    fetchedEntries.value = data
  } finally {
    loadingEntries.value = false
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  loadEntriesIfNeeded()
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const sectionLabel = (z) =>
  ({ main: 'Mainboard', side: 'Sideboard', maybe: 'Maybeboard' })[z] || z
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="assemble-title"
    >
      <h2 id="assemble-title" class="display">Mark deck as assembled</h2>
      <p class="subtitle">We'll log every card here as physically owned in this deck.</p>

      <div v-if="showZoneToggles" class="section">
        <span class="label">Sections to assemble</span>
        <div class="sub-sections">
          <Checkbox
            v-for="z in presentSections"
            :key="z"
            v-model="sectionsState[z]"
            :label="sectionLabel(z)"
          />
        </div>
      </div>

      <div class="section">
        <span class="label">I'm missing some cards</span>
        <div class="exclude-search">
          <input
            type="text"
            class="search-input"
            placeholder="Search this deck…"
            v-model="search"
            @focus="searchOpen = true"
            @blur="onSearchBlur"
            name="exclude-search"
            autocomplete="off"
          />
          <ul
            v-if="searchOpen && filteredCandidates.length"
            class="search-menu"
            role="listbox"
          >
            <li
              v-for="c in filteredCandidates"
              :key="`${c.scryfall_id}|${c.zone}`"
              class="search-item"
              role="option"
              @mousedown.prevent="addExclude(c)"
            >
              <span class="cand-name">{{ c.name }}</span>
              <span v-if="c.zone !== 'main'" class="cand-zone">{{ c.zone }}</span>
            </li>
          </ul>
        </div>

        <ul v-if="excludes.length" class="excludes">
          <li
            v-for="(row, idx) in excludes"
            :key="`${row.scryfall_id}|${row.zone}`"
            class="chip"
          >
            <span class="chip-name">
              {{ row.name }}
              <span v-if="row.zone !== 'main'" class="chip-zone">({{ row.zone }})</span>
            </span>
            <template v-if="!row.is_commander && !row.is_signature_spell && row.slotQty > 1">
              <input
                class="chip-qty"
                type="number"
                min="1"
                :max="row.slotQty"
                v-model.number="row.qty"
                @change="clampExcludeQty(idx)"
                :name="`exclude-${row.scryfall_id}-${row.zone}-qty`"
                aria-label="Excluded count"
              />
              <span class="chip-of">of {{ row.slotQty }}</span>
            </template>
            <button
              type="button"
              class="chip-remove"
              @click="removeExclude(idx)"
              aria-label="Remove exclude"
            >×</button>
          </li>
        </ul>
      </div>

      <div class="actions">
        <button type="button" @click="emit('close')" :disabled="submitting">Cancel</button>
        <button
          type="button"
          class="primary"
          @click="submit"
          :disabled="!canSubmit"
        >
          {{ submitting ? 'Assembling…' : 'Mark as assembled' }}
        </button>
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
.section {
  margin-bottom: 18px;
}
.section:last-of-type {
  margin-bottom: 8px;
}
.sub-sections {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin-bottom: 8px;
}
.exclude-search {
  position: relative;
}
.search-input {
  width: 100%;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  color: var(--ink-100);
  padding: 6px 10px;
  font-size: 13px;
}
.search-input:focus {
  outline: none;
  border-color: var(--amber);
}
.search-menu {
  position: absolute;
  top: calc(100% + 2px);
  left: 0;
  right: 0;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  list-style: none;
  margin: 0;
  padding: 4px 0;
  max-height: 220px;
  overflow-y: auto;
  z-index: 10;
}
.search-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  font-size: 13px;
  cursor: pointer;
  color: var(--ink-100);
}
.search-item:hover {
  background: var(--amber-dim);
}
.cand-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cand-zone, .chip-zone {
  text-transform: uppercase;
  font-size: 10px;
  color: var(--ink-50);
  letter-spacing: 0.06em;
}
.excludes {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.chip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px 4px 10px;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 4px;
  font-size: 13px;
  color: var(--ink-100);
}
.chip-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chip-qty {
  width: 52px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 3px;
  color: var(--ink-100);
  padding: 2px 6px;
  font-size: 12px;
  text-align: right;
}
.chip-of {
  font-size: 11px;
  color: var(--ink-50);
}
.chip-remove {
  background: transparent;
  border: none;
  color: var(--ink-50);
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  padding: 0 4px;
}
.chip-remove:hover {
  color: var(--cond-dmg, #d46a6a);
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 22px;
}
.actions .primary {
  background: var(--amber);
  color: #1a120c;
  border: 1px solid var(--amber);
}
.actions .primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
