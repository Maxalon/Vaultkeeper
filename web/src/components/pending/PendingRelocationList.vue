<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useCollectionStore } from '../../stores/collection'
import { useToast } from '../../composables/useToast'
import Checkbox from '../Checkbox.vue'

/**
 * Renders the user's pending-relocation copies and lets them resolve
 * each row to a real Location (or "Sold / discarded"). Two scopes:
 *
 *   - global: every pending CE the user has, regardless of source
 *     deck. Powers the /pending route.
 *   - deck:   filtered to copies whose source_deck_id matches
 *     `:deck-id`. Powers the per-deck Pending tab on DeckView.
 *
 * Per locked decision 12 the same component backs both — the only
 * difference is the row filter and the surrounding chrome.
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
//   targetLocationId = null   → no action picked (row stays pending)
//   targetLocationId = -1     → "Sold / discarded" sentinel
//   targetLocationId = <int>  → move to that user-managed location
const assignments = ref({})  // { [id]: { target, selected } }

const realLocations = computed(() => collection.locations)

// Bulk-apply target — when set, used for any row that has `selected`
// but no per-row target picked yet.
const bulkTarget = ref('')

const selectedIds = computed(() =>
  rows.value.filter((r) => assignments.value[r.id]?.selected).map((r) => r.id),
)

const anyResolutionPicked = computed(() => {
  for (const r of rows.value) {
    const a = assignments.value[r.id]
    if (a && (a.target !== null || a.discard)) return true
  }
  return false
})

const DISCARD_SENTINEL = '__discard__'

async function load() {
  loading.value = true
  error.value = ''
  try {
    rows.value = await collection.fetchPendingList(
      props.scope === 'deck' && props.deckId ? { deckId: props.deckId } : {},
    )
    // Reset row state when the list changes shape.
    const next = {}
    for (const r of rows.value) {
      const prev = assignments.value[r.id]
      next[r.id] = prev ?? { target: null, discard: false, selected: false }
    }
    assignments.value = next
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load pending copies'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.scope, props.deckId], load)

function onRowTargetChange(id, value) {
  const a = (assignments.value[id] ||= { target: null, discard: false, selected: false })
  if (value === '') {
    a.target = null
    a.discard = false
    return
  }
  if (value === DISCARD_SENTINEL) {
    a.target = null
    a.discard = true
    return
  }
  a.target = Number(value)
  a.discard = false
}

function rowResolutionValue(id) {
  const a = assignments.value[id]
  if (!a) return ''
  if (a.discard) return DISCARD_SENTINEL
  if (a.target !== null) return String(a.target)
  return ''
}

function applyBulkToSelected() {
  if (!bulkTarget.value) return
  for (const id of selectedIds.value) {
    onRowTargetChange(id, bulkTarget.value)
  }
}

function selectAll(value) {
  for (const r of rows.value) {
    const a = (assignments.value[r.id] ||= { target: null, discard: false, selected: false })
    a.selected = !!value
  }
}

const allSelected = computed(() =>
  rows.value.length > 0 && rows.value.every((r) => assignments.value[r.id]?.selected),
)

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
      } else if (a.target !== null) {
        payload.push({ collection_entry_id: r.id, target_location_id: a.target })
      }
    }
    if (payload.length === 0) return
    const result = await collection.resolvePending(payload)
    const moved = (result.resolved || 0) + (result.merged || 0)
    const discarded = result.discarded || 0
    const parts = []
    if (moved > 0) parts.push(`Moved ${moved} cop${moved === 1 ? 'y' : 'ies'}`)
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
  <section class="pending-list">
    <header v-if="!hideHeader" class="pending-header">
      <h2 class="display">Pending Relocation</h2>
      <span class="count">{{ rows.length }} card{{ rows.length === 1 ? '' : 's' }} awaiting re-shelving</span>
    </header>

    <div v-if="rows.length" class="bulk-bar">
      <Checkbox
        :model-value="allSelected"
        @update:model-value="selectAll"
        :label="`Select all (${rows.length})`"
      />
      <select
        v-model="bulkTarget"
        class="select"
        name="bulk-target"
        :disabled="selectedIds.length === 0"
        aria-label="Bulk move target"
      >
        <option value="">Move {{ selectedIds.length }} selected to…</option>
        <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
        <option :value="DISCARD_SENTINEL">Sold / discarded</option>
      </select>
      <button
        type="button"
        class="btn"
        :disabled="!bulkTarget || selectedIds.length === 0"
        @click="applyBulkToSelected"
      >Apply to selected</button>
    </div>

    <div v-if="loading" class="empty">Loading…</div>
    <div v-else-if="!rows.length" class="empty">
      Nothing pending — every card has a home.
    </div>
    <div v-else-if="error" class="empty error">{{ error }}</div>

    <ul v-if="rows.length" class="rows">
      <li v-for="row in rows" :key="row.id" class="row">
        <Checkbox
          :model-value="!!assignments[row.id]?.selected"
          @update:model-value="(v) => (assignments[row.id] = { ...(assignments[row.id] || { target: null, discard: false }), selected: v })"
          :name="`pending-select-${row.id}`"
        />

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
            <span v-if="row.source_deck" class="from">from {{ sourceDeckLabel(row) }}</span>
          </div>
        </div>

        <select
          class="select target-select"
          :value="rowResolutionValue(row.id)"
          @change="(e) => onRowTargetChange(row.id, e.target.value)"
          :name="`pending-target-${row.id}`"
          aria-label="Resolution"
        >
          <option value="">Pick a destination…</option>
          <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
          <option :value="DISCARD_SENTINEL">Sold / discarded</option>
        </select>
      </li>
    </ul>

    <footer v-if="rows.length" class="pending-footer">
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
.pending-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px 22px 28px;
  min-height: 0;
  flex: 1 1 auto;
}
.pending-header {
  display: flex;
  align-items: baseline;
  gap: 14px;
}
.pending-header h2 {
  font-size: 22px;
  color: var(--amber);
  margin: 0;
}
.count {
  font-size: 12px;
  color: var(--ink-50);
}
.bulk-bar {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 8px 10px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: 4px;
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
  overflow-y: auto;
  min-height: 0;
}
.row {
  display: grid;
  grid-template-columns: auto 56px 1fr 220px;
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
.pending-footer {
  display: flex;
  justify-content: flex-end;
  padding-top: 4px;
}
</style>
