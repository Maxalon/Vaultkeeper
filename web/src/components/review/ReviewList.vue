<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
 *   - default_values_applied — the row's location IS the deck, so
 *                              changing it would silently decouple the
 *                              copy from the deck. Instead we expose
 *                              inline condition/foil editors plus an
 *                              "Accept" toggle (and "Accept defaults
 *                              for all" at the section level), all of
 *                              which clear the reason in place. Editing
 *                              a value auto-arms accept.
 *   - card_data_changed      — pick a destination, or sold/discarded.
 *                              Future: rebind-to-new-printing UI.
 */
const props = defineProps({
  scope: { type: String, default: 'global' }, // 'global' | 'deck'
  deckId: { type: Number, default: null },
  // Optional review_reason filter — when set, only that section's rows
  // are fetched. Used by deep-links from the post-assemble toast.
  reason: { type: String, default: null },
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
//                                                             clear reason;
//                                                             condition/foil
//                                                             override the
//                                                             original values
//                                                             when they differ.
const assignments = ref({})  // { [id]: { target, discard, accept_defaults, selected, condition, foil } }

const CONDITIONS = ['NM', 'LP', 'MP', 'HP', 'DMG']

const REASON_BADGES = {
  no_location:            'No location',
  default_values_applied: 'Default values',
  card_data_changed:      'Data changed',
}

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

// Multi-select mode: lets the user pick rows and apply a single
// destination/condition/foil/accept to all of them at once. Selection is
// constrained to a single review_reason group — the actions exposed
// differ between groups, so cross-group selection has no coherent
// bulk-bar UI.
const selecting = ref(false)
const selectedIds = ref(new Set())
const anchorId = ref(null)
const anchorGroup = ref(null)
const bulkTarget = ref('')
const bulkCondition = ref('NM')
const bulkFoil = ref(false)

// Per-group batch resolve target (for no_location / card_data_changed groups).
const groupBatchTarget = ref({})

const selectedCount = computed(() => selectedIds.value.size)

function clearSelection() {
  selectedIds.value = new Set()
  anchorId.value = null
  anchorGroup.value = null
  bulkTarget.value = ''
}

function toggleSelecting() {
  selecting.value = !selecting.value
  if (!selecting.value) clearSelection()
}

function onRowClick(row, evt) {
  if (!selecting.value) return
  evt.preventDefault()

  const reason = row.review_reason
  const ids = selectedIds.value

  // Cross-group click while a selection exists: drop the prior selection
  // and behave like a fresh plain click in the new group.
  if (ids.size > 0 && reason !== anchorGroup.value) {
    selectedIds.value = new Set([row.id])
    anchorId.value = row.id
    anchorGroup.value = reason
    return
  }

  if (evt.ctrlKey || evt.metaKey) {
    const next = new Set(ids)
    if (next.has(row.id)) next.delete(row.id)
    else next.add(row.id)
    selectedIds.value = next
    anchorId.value = row.id
    anchorGroup.value = reason
    if (next.size === 0) anchorGroup.value = null
    return
  }

  if (evt.shiftKey && anchorId.value !== null && anchorGroup.value === reason) {
    const group = grouped.value.find((g) => g.reason === reason)
    if (group) {
      const list = group.rows
      const aIdx = list.findIndex((r) => r.id === anchorId.value)
      const cIdx = list.findIndex((r) => r.id === row.id)
      if (aIdx !== -1 && cIdx !== -1) {
        const [lo, hi] = aIdx <= cIdx ? [aIdx, cIdx] : [cIdx, aIdx]
        const next = new Set()
        for (let i = lo; i <= hi; i++) next.add(list[i].id)
        selectedIds.value = next
        return
      }
    }
  }

  selectedIds.value = new Set([row.id])
  anchorId.value = row.id
  anchorGroup.value = reason
}

function onKeydown(e) {
  if (!selecting.value) return
  if (e.key === 'Escape' && selectedIds.value.size > 0) {
    clearSelection()
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

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
    const opts = {}
    if (props.scope === 'deck' && props.deckId) opts.deckId = props.deckId
    if (props.reason) opts.reason = props.reason
    rows.value = await collection.fetchReviewList(opts)
    const next = {}
    for (const r of rows.value) {
      const prev = assignments.value[r.id]
      next[r.id] = prev ?? {
        target: null,
        discard: false,
        accept_defaults: false,
        selected: false,
        condition: r.condition,
        foil: !!r.foil,
      }
    }
    assignments.value = next
    // Selection survives only within a load: previously-selected rows
    // may have been resolved away. `selecting` mode itself is kept.
    selectedIds.value = new Set()
    anchorId.value = null
    anchorGroup.value = null
    bulkTarget.value = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load review queue'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.scope, props.deckId, props.reason], load)

function ensureAssignment(row) {
  let a = assignments.value[row.id]
  if (!a) {
    a = {
      target: null,
      discard: false,
      accept_defaults: false,
      selected: false,
      condition: row.condition,
      foil: !!row.foil,
    }
    assignments.value[row.id] = a
  }
  return a
}

function onRowTargetChange(row, value) {
  const a = ensureAssignment(row)
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

// Editing condition or foil on a default_values_applied row arms accept
// — the user is correcting what assemble minted, which is the only
// resolution that makes sense for those rows.
function onRowConditionChange(row, value) {
  const a = ensureAssignment(row)
  a.condition = value
  a.accept_defaults = true
  a.target = null
  a.discard = false
}

function onRowFoilChange(row, value) {
  const a = ensureAssignment(row)
  a.foil = !!value
  a.accept_defaults = true
  a.target = null
  a.discard = false
}

function onRowAcceptToggle(row, checked) {
  const a = ensureAssignment(row)
  a.accept_defaults = !!checked
  if (checked) {
    a.target = null
    a.discard = false
  }
}

function isEdited(row) {
  const a = assignments.value[row.id]
  if (!a) return false
  return a.condition !== row.condition || !!a.foil !== !!row.foil
}

function rowResolutionValue(id) {
  const a = assignments.value[id]
  if (!a) return ''
  if (a.discard) return DISCARD_SENTINEL
  if (a.target !== null) return String(a.target)
  return ''
}

function onBulkTargetChange(value) {
  bulkTarget.value = value
  if (value === '') return
  for (const id of selectedIds.value) {
    const r = rows.value.find((x) => x.id === id)
    if (!r) continue
    onRowTargetChange(r, value)
  }
  bulkTarget.value = ''
}

function bulkDiscard() {
  for (const id of selectedIds.value) {
    const r = rows.value.find((x) => x.id === id)
    if (!r) continue
    onRowTargetChange(r, DISCARD_SENTINEL)
  }
}

function onBulkConditionChange(value) {
  bulkCondition.value = value
  for (const id of selectedIds.value) {
    const r = rows.value.find((x) => x.id === id)
    if (!r) continue
    onRowConditionChange(r, value)
  }
}

function onBulkFoilChange(value) {
  bulkFoil.value = !!value
  for (const id of selectedIds.value) {
    const r = rows.value.find((x) => x.id === id)
    if (!r) continue
    onRowFoilChange(r, !!value)
  }
}

function bulkAccept() {
  for (const id of selectedIds.value) {
    const r = rows.value.find((x) => x.id === id)
    if (!r) continue
    onRowAcceptToggle(r, true)
  }
}

function acceptDefaultsForGroup(reason) {
  const inGroup = rows.value.filter((r) => r.review_reason === reason)
  for (const r of inGroup) {
    const a = ensureAssignment(r)
    a.target = null
    a.discard = false
    a.accept_defaults = true
  }
}

async function resolveAllInGroup(reason, locationId) {
  if (!locationId) return
  submitting.value = true
  try {
    const payload = rows.value
      .filter((r) => r.review_reason === reason)
      .map((r) => ({ collection_entry_id: r.id, target_location_id: Number(locationId) }))
    if (!payload.length) return
    const result = await collection.resolveReview(payload)
    const moved = (result.resolved || 0) + (result.merged || 0)
    const discarded = result.discarded || 0
    const parts = []
    if (moved > 0) parts.push(`Moved ${moved} cop${moved === 1 ? 'y' : 'ies'}`)
    if (discarded > 0) parts.push(`discarded ${discarded}`)
    toast.success(parts.length ? parts.join(', ') + '.' : 'Nothing to apply.')
    groupBatchTarget.value[reason] = ''
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Resolve failed')
  } finally {
    submitting.value = false
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
        const row = { collection_entry_id: r.id, accept_defaults: true }
        // Only forward overrides that actually differ from what
        // assemble minted — keeps the wire payload minimal and the
        // backend write a no-op when nothing changed.
        if (a.condition !== r.condition) row.condition = a.condition
        if (!!a.foil !== !!r.foil)       row.foil      = !!a.foil
        payload.push(row)
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

    <div v-if="rows.length" class="review-toolbar">
      <button
        type="button"
        class="vk-btn vk-btn-primary"
        :class="{ active: selecting }"
        @click="toggleSelecting"
      >Select</button>
    </div>

    <div v-if="loading" class="empty">Loading…</div>
    <div v-else-if="!rows.length" class="empty">
      Nothing to review — you're all caught up
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
          <div v-else class="group-batch">
            <select
              class="select"
              :value="groupBatchTarget[group.reason] || ''"
              :name="`batch-target-${group.reason}`"
              :disabled="submitting"
              @change="(e) => (groupBatchTarget[group.reason] = e.target.value)"
            >
              <option value="">Resolve all to…</option>
              <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
            </select>
            <button
              type="button"
              class="btn"
              :disabled="!groupBatchTarget[group.reason] || submitting"
              @click="resolveAllInGroup(group.reason, groupBatchTarget[group.reason])"
            >Resolve all</button>
          </div>
        </header>

        <ul class="rows">
          <li
            v-for="row in group.rows"
            :key="row.id"
            class="row"
            :class="{ selectable: selecting, selected: selectedIds.has(row.id) }"
            @click="(e) => onRowClick(row, e)"
          >
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
                <span v-if="row.review_reason !== 'default_values_applied'" class="cond">{{ row.condition }}</span>
                <span v-if="row.review_reason !== 'default_values_applied' && row.foil" class="foil">FOIL</span>
                <span v-if="row.card?.set_code" class="set">{{ row.card.set_code.toUpperCase() }}</span>
                <span v-if="row.location_name" class="loc">in {{ row.location_name }}</span>
                <span v-if="row.source_deck" class="from">from {{ sourceDeckLabel(row) }}</span>
                <span
                  v-if="assignments[row.id]?.accept_defaults"
                  class="accept-marker"
                >will accept{{ isEdited(row) ? ' (edited)' : ' defaults' }}</span>
                <span
                  class="reason-badge"
                  :data-reason="row.review_reason"
                >{{ REASON_BADGES[row.review_reason] }}</span>
              </div>
            </div>

            <div v-if="!selecting && row.review_reason === 'default_values_applied'" class="defaults-editor">
              <label class="field">
                <span class="field-label">Condition</span>
                <select
                  class="select"
                  :value="assignments[row.id]?.condition ?? row.condition"
                  @change="(e) => onRowConditionChange(row, e.target.value)"
                  :name="`review-condition-${row.id}`"
                  aria-label="Condition"
                >
                  <option v-for="c in CONDITIONS" :key="c" :value="c">{{ c }}</option>
                </select>
              </label>
              <label class="field foil-field">
                <input
                  type="checkbox"
                  :checked="assignments[row.id]?.foil ?? !!row.foil"
                  @change="(e) => onRowFoilChange(row, e.target.checked)"
                  :name="`review-foil-${row.id}`"
                />
                <span class="field-label">Foil</span>
              </label>
              <label class="field accept-field">
                <input
                  type="checkbox"
                  :checked="!!assignments[row.id]?.accept_defaults"
                  @change="(e) => onRowAcceptToggle(row, e.target.checked)"
                  :name="`review-accept-${row.id}`"
                />
                <span class="field-label">Accept</span>
              </label>
            </div>

            <select
              v-else-if="!selecting"
              class="select target-select"
              :value="rowResolutionValue(row.id)"
              @change="(e) => onRowTargetChange(row, e.target.value)"
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

    <div v-if="selecting && selectedCount > 0" class="bulk-bar">
      <span class="sel-count">{{ selectedCount }} selected</span>
      <template v-if="anchorGroup === 'default_values_applied'">
        <label class="field">
          <span class="field-label">Condition</span>
          <select
            class="select"
            :value="bulkCondition"
            @change="(e) => onBulkConditionChange(e.target.value)"
            name="bulk-condition"
            aria-label="Bulk condition"
          >
            <option v-for="c in CONDITIONS" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>
        <label class="field foil-field">
          <input
            type="checkbox"
            :checked="bulkFoil"
            @change="(e) => onBulkFoilChange(e.target.checked)"
            name="bulk-foil"
          />
          <span class="field-label">Foil</span>
        </label>
        <button type="button" class="btn primary" @click="bulkAccept">Accept</button>
      </template>
      <template v-else>
        <select
          class="select"
          :value="bulkTarget"
          @change="(e) => onBulkTargetChange(e.target.value)"
          name="bulk-target"
          aria-label="Bulk destination"
        >
          <option value="">Pick destination…</option>
          <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
        </select>
        <button type="button" class="btn" @click="bulkDiscard">Discard</button>
      </template>
      <button
        type="button"
        class="btn close-btn"
        aria-label="Clear selection"
        @click="clearSelection"
      >×</button>
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
  grid-template-columns: 56px 1fr minmax(220px, 320px);
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
.defaults-editor {
  display: flex;
  align-items: center;
  gap: 12px;
  justify-self: stretch;
  flex-wrap: wrap;
}
.field {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--ink-50);
  cursor: pointer;
}
.field .select {
  min-width: 64px;
  padding: 3px 6px;
}
.field-label {
  letter-spacing: 0.04em;
}
.foil-field input,
.accept-field input {
  cursor: pointer;
}
.accept-field {
  margin-left: auto;
  color: var(--amber);
  font-weight: 600;
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
.review-toolbar {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.row.selectable {
  cursor: pointer;
  user-select: none;
  grid-template-columns: 56px 1fr;
}
.row.selected {
  border-color: var(--amber);
  background: var(--amber-dim);
}
.bulk-bar {
  position: sticky;
  bottom: 44px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
  background: var(--bg-2);
  border-top: 1px solid var(--hairline);
  border-bottom: 1px solid var(--hairline);
  margin: 0 -22px;
}
.bulk-bar .sel-count {
  font-size: 12px;
  color: var(--amber);
  font-weight: 600;
}
.bulk-bar .close-btn {
  margin-left: auto;
  font-size: 14px;
  line-height: 1;
  padding: 2px 8px;
}
.reason-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.05em;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  color: var(--ink-50);
  text-transform: uppercase;
}
.reason-badge[data-reason="no_location"] {
  border-color: var(--amber);
  color: var(--amber);
}
.reason-badge[data-reason="card_data_changed"] {
  border-color: #7ab;
  color: #7ab;
}
.group-batch {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 2px;
}
.group-batch .select {
  min-width: 160px;
}
</style>
