<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useCollectionStore } from '../../stores/collection'
import { useToast } from '../../composables/useToast'
import Checkbox from '../Checkbox.vue'

/**
 * Renders the user's review-queued copies grouped by review_reason and
 * lets them resolve each row. Two scopes:
 *
 *   - global: every flagged CE the user has, regardless of source deck.
 *     Powers the /review route.
 *   - deck:   filtered to copies whose source_deck_id matches `:deck-id`.
 *     Powers the per-deck Review tab on DeckView.
 *
 * Reasons:
 *
 *   - no_location            — pick a destination, or sold/discarded.
 *   - default_values_applied — pick a destination, sold/discarded, or
 *                              "Accept defaults for all" (section button)
 *                              which clears the reason without moving
 *                              the row.
 *   - card_data_changed      — pick a destination, or sold/discarded.
 *                              Future: rebind-to-new-printing UI.
 */
const props = defineProps({
  scope: { type: String, default: 'global' }, // 'global' | 'deck'
  deckId: { type: Number, default: null },
  // Hide the page-level title row (the global view renders its own).
  hideHeader: { type: Boolean, default: false },
})

const collection = useCollectionStore()
const toast = useToast()

const rows = ref([])
const loading = ref(false)
const submitting = ref(false)
const error = ref('')

// Per-row resolution state. Keyed by CE id.
//   target = null, discard = false, accept_defaults = false → no action picked
//   target = -1                                             → "Sold / discarded"
//   target = <int>                                          → move there
//   accept_defaults = true                                  → keep in place,
//                                                             clear reason
const assignments = ref({})  // { [id]: { target, discard, accept_defaults, selected } }

const realLocations = computed(() => collection.locations)

const REASON_LABELS = {
  no_location:            { title: 'Cards without a location',
                            blurb: 'Pick a destination for each copy.' },
  default_values_applied: { title: 'Cards with default values applied',
                            blurb: 'Assemble minted these with NM / non-foil. Accept the defaults or correct.' },
  card_data_changed:      { title: 'Cards whose data changed',
                            blurb: 'Scryfall dropped or migrated the printing. Re-shelve or discard.' },
}
const REASON_ORDER = ['no_location', 'default_values_applied', 'card_data_changed']

// Group rows by their review_reason for section rendering.
const grouped = computed(() => {
  const out = []
  for (const reason of REASON_ORDER) {
    const inGroup = rows.value.filter((r) => r.review_reason === reason)
    if (inGroup.length === 0) continue
    out.push({ reason, rows: inGroup })
  }
  return out
})

const DISCARD_SENTINEL = '__discard__'

const anyResolutionPicked = computed(() => {
  for (const r of rows.value) {
    const a = assignments.value[r.id]
    if (a && (a.target !== null || a.discard || a.accept_defaults)) return true
  }
  return false
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    rows.value = await collection.fetchReviewList(
      props.scope === 'deck' && props.deckId ? { deckId: props.deckId } : {},
    )
    const next = {}
    for (const r of rows.value) {
      const prev = assignments.value[r.id]
      next[r.id] = prev ?? { target: null, discard: false, accept_defaults: false, selected: false }
    }
    assignments.value = next
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load review queue'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.scope, props.deckId], load)

function onRowTargetChange(id, value) {
  const a = (assignments.value[id] ||= { target: null, discard: false, accept_defaults: false, selected: false })
  if (value === '') {
    a.target = null
    a.discard = false
    a.accept_defaults = false
    return
  }
  if (value === DISCARD_SENTINEL) {
    a.target = null
    a.discard = true
    a.accept_defaults = false
    return
  }
  a.target = Number(value)
  a.discard = false
  a.accept_defaults = false
}

function rowResolutionValue(id) {
  const a = assignments.value[id]
  if (!a) return ''
  if (a.discard) return DISCARD_SENTINEL
  if (a.target !== null) return String(a.target)
  return ''
}

function acceptDefaultsForGroup(reason) {
  const inGroup = rows.value.filter((r) => r.review_reason === reason)
  for (const r of inGroup) {
    const a = (assignments.value[r.id] ||= { target: null, discard: false, accept_defaults: false, selected: false })
    a.target = null
    a.discard = false
    a.accept_defaults = true
  }
}

async function applyResolutions() {
  if (!anyResolutionPicked.value) return
  submitting.value = true
  try {
    const payload = []
    for (const r of rows.value) {
      const a = assignments.value[r.id]
      if (!a) continue
      if (a.discard) {
        payload.push({ collection_entry_id: r.id, discard: true })
      } else if (a.accept_defaults) {
        payload.push({ collection_entry_id: r.id, accept_defaults: true })
      } else if (a.target !== null) {
        payload.push({ collection_entry_id: r.id, target_location_id: a.target })
      }
    }
    if (payload.length === 0) return
    const result = await collection.resolveReview(payload)
    const moved = (result.resolved || 0) + (result.merged || 0)
    const discarded = result.discarded || 0
    const accepted = result.accepted || 0
    const parts = []
    if (moved > 0) parts.push(`Moved ${moved} cop${moved === 1 ? 'y' : 'ies'}`)
    if (accepted > 0) parts.push(`accepted ${accepted}`)
    if (discarded > 0) parts.push(`discarded ${discarded}`)
    toast.success(parts.length ? parts.join(', ') + '.' : 'Nothing to apply.')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Resolve failed')
  } finally {
    submitting.value = false
  }
}

function rowImage(row) {
  return row.card?.image_small || row.card?.image_normal || null
}

function sourceDeckLabel(row) {
  const s = row.source_deck
  if (!s) return ''
  if (s.deleted) return `${s.deck_name} (deleted)`
  return s.deck_name
}
</script>

<template>
  <section class="review-list">
    <header v-if="!hideHeader" class="review-header">
      <h2 class="display">Review</h2>
      <span class="count">
        {{ rows.length }} card{{ rows.length === 1 ? '' : 's' }} need{{ rows.length === 1 ? 's' : '' }} attention
      </span>
    </header>

    <div v-if="loading" class="empty">Loading…</div>
    <div v-else-if="!rows.length" class="empty">
      Nothing to review — every card is in good standing.
    </div>
    <div v-else-if="error" class="empty error">{{ error }}</div>

    <div v-if="rows.length" class="groups">
      <section v-for="group in grouped" :key="group.reason" class="group">
        <header class="group-header">
          <div class="group-title">
            <h3>{{ REASON_LABELS[group.reason].title }}</h3>
            <span class="group-count">{{ group.rows.length }}</span>
          </div>
          <div class="group-blurb">{{ REASON_LABELS[group.reason].blurb }}</div>
          <button
            v-if="group.reason === 'default_values_applied'"
            type="button"
            class="btn"
            @click="acceptDefaultsForGroup(group.reason)"
          >Accept defaults for all</button>
        </header>

        <ul class="rows">
          <li v-for="row in group.rows" :key="row.id" class="row">
            <img
              v-if="rowImage(row)"
              :src="rowImage(row)"
              :alt="row.card?.name || ''"
              class="thumb"
            />
            <div v-else class="thumb thumb-empty"></div>

            <div class="meta">
              <div class="name">{{ row.card?.name || '—' }}</div>
              <div class="sub">
                <span class="qty">×{{ row.quantity }}</span>
                <span class="cond">{{ row.condition }}</span>
                <span v-if="row.foil" class="foil">FOIL</span>
                <span v-if="row.card?.set_code" class="set">{{ row.card.set_code.toUpperCase() }}</span>
                <span v-if="row.location_name" class="loc">in {{ row.location_name }}</span>
                <span v-if="row.source_deck" class="from">from {{ sourceDeckLabel(row) }}</span>
                <span
                  v-if="assignments[row.id]?.accept_defaults"
                  class="accept-marker"
                >will accept defaults</span>
              </div>
            </div>

            <select
              class="select target-select"
              :value="rowResolutionValue(row.id)"
              @change="(e) => onRowTargetChange(row.id, e.target.value)"
              :name="`review-target-${row.id}`"
              aria-label="Resolution"
            >
              <option value="">Pick a destination…</option>
              <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
              <option :value="DISCARD_SENTINEL">Sold / discarded</option>
            </select>
          </li>
        </ul>
      </section>
    </div>

    <footer v-if="rows.length" class="review-footer">
      <button
        type="button"
        class="btn primary"
        :disabled="submitting || !anyResolutionPicked"
        @click="applyResolutions"
      >
        {{ submitting ? 'Applying…' : 'Apply' }}
      </button>
    </footer>
  </section>
</template>

<style scoped>
.review-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 18px 22px 28px;
  min-height: 0;
  flex: 1 1 auto;
}
.review-header {
  display: flex;
  align-items: baseline;
  gap: 14px;
}
.review-header h2 {
  font-size: 22px;
  color: var(--amber);
  margin: 0;
}
.count {
  font-size: 12px;
  color: var(--ink-50);
}
.groups {
  display: flex;
  flex-direction: column;
  gap: 18px;
  overflow-y: auto;
  min-height: 0;
}
.group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.group-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 10px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 4px;
}
.group-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.group-title h3 {
  font-size: 14px;
  color: var(--ink-100);
  margin: 0;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.group-count {
  font-size: 11px;
  color: var(--ink-50);
}
.group-blurb {
  font-size: 12px;
  color: var(--ink-50);
}
.group-header .btn {
  align-self: flex-start;
}
.empty {
  padding: 24px;
  text-align: center;
  color: var(--ink-50);
  font-size: 13px;
  background: var(--bg-1);
  border: 1px dashed var(--hairline);
  border-radius: 4px;
}
.empty.error { color: var(--cond-dmg, #d46a6a); }
.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.row {
  display: grid;
  grid-template-columns: 56px 1fr 220px;
  gap: 10px;
  align-items: center;
  padding: 6px 10px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 4px;
}
.thumb {
  width: 56px;
  height: 78px;
  object-fit: cover;
  border-radius: 3px;
  background: var(--bg-0);
}
.thumb-empty {
  background: var(--bg-0);
  border: 1px dashed var(--hairline);
}
.meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.name {
  font-size: 13px;
  color: var(--ink-100);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sub {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 11px;
  color: var(--ink-50);
}
.sub .qty { color: var(--ink-100); }
.sub .foil { color: var(--amber); letter-spacing: 0.06em; font-weight: 600; }
.sub .set { font-family: var(--font-mono), monospace; }
.sub .from { font-style: italic; }
.sub .accept-marker {
  color: var(--amber);
  font-style: italic;
}
.select {
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  border-radius: 3px;
  color: var(--ink-100);
  padding: 4px 8px;
  font-size: 12px;
  min-width: 160px;
}
.select:focus {
  outline: none;
  border-color: var(--amber);
}
.target-select {
  justify-self: stretch;
}
.btn {
  padding: 5px 12px;
  font-size: 11px;
  color: var(--ink-70);
  background: rgba(20, 15, 9, 0.6);
  border: 1px solid var(--hairline-strong, var(--hairline));
  border-radius: 3px;
  letter-spacing: 0.04em;
  cursor: pointer;
}
.btn:hover:not(:disabled) {
  color: var(--ink-100);
  border-color: var(--amber);
}
.btn.primary {
  background: var(--amber);
  border-color: var(--amber);
  color: #1a120c;
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.review-footer {
  display: flex;
  justify-content: flex-end;
  padding-top: 4px;
}
</style>
