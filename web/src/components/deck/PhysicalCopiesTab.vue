<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'
import ConditionBadge from '../ConditionBadge.vue'
import AddCopiesModal from './AddCopiesModal.vue'
import EditPhysicalCopyModal from './EditPhysicalCopyModal.vue'

/**
 * Physical Copies tab — single view for "what does this deck still need?"
 * and "let me clean up the condition / foil / notes / printing of every
 * copy actually in the deck." Sits alongside Analysis / Illegalities in
 * the deckbuilder tab system.
 */
const deck = useDeckStore()
const tabs = useTabsStore()

// Stable sort by card name for both sections.
const sortedEntries = computed(() => {
  const list = [...(deck.entries || [])]
  list.sort((a, b) =>
    (a.scryfall_card?.name || '').localeCompare(b.scryfall_card?.name || '')
  )
  return list
})

const missingRows = computed(() =>
  sortedEntries.value.filter(
    (e) => e.physical_copy_id == null
      && !e.is_commander // commanders without copies still glow on the deck name; not a sourcing concern
  ),
)
const boundRows = computed(() =>
  sortedEntries.value.filter((e) => e.physical_copy_id != null),
)

const counts = computed(() => {
  let bound = 0
  let missing = 0
  for (const e of deck.entries || []) {
    const q = Number(e.quantity) || 0
    if (e.physical_copy_id != null) bound += q
    else missing += q
  }
  return { bound, missing, total: bound + missing }
})

const addTarget = ref(null)   // { entry } when AddCopiesModal is open
const editTarget = ref(null)  // { entry } when EditPhysicalCopyModal is open

function openAdd(entry) {
  addTarget.value = { entry }
}
function openEdit(entry) {
  editTarget.value = { entry }
}

function jumpToCard(entry) {
  tabs.openTab('deck')
  deck.setActiveEntry(entry.id)
}

function describePhysical(p) {
  if (!p) return ''
  const bits = [p.condition]
  if (p.foil) bits.push('foil')
  if (p.location_name) bits.push(p.location_name)
  return bits.join(' · ')
}

// AddCopiesModal expects { deck, bound, wanted, card, scryfallId, zone, category }.
// For a missing row, bound is null (the row IS the wanted-only entry).
const addModalProps = computed(() => {
  if (!addTarget.value) return null
  const entry = addTarget.value.entry
  return {
    deck: { id: deck.deck.id, name: deck.deck.name },
    bound: null,
    wanted: entry,
    card: entry.scryfall_card,
    scryfallId: entry.scryfall_id,
    zone: entry.zone,
    category: entry.category,
  }
})
</script>

<template>
  <div class="physical-tab">
    <header class="banner" :class="{ ok: !counts.missing }">
      <template v-if="!counts.missing">
        <span class="check">✓</span>
        <span>{{ counts.bound }} of {{ counts.total }} copies have a physical card.</span>
      </template>
      <template v-else>
        <span>
          {{ counts.bound }} of {{ counts.total }} copies have a physical card ·
          {{ counts.missing }} still need sourcing.
        </span>
      </template>
    </header>

    <section v-if="missingRows.length" class="section">
      <h3 class="section-head">Missing copies</h3>
      <ul class="rows">
        <li v-for="row in missingRows" :key="`m-${row.id}`" class="row missing">
          <button
            type="button"
            class="row-card"
            @click="jumpToCard(row)"
          >{{ row.scryfall_card?.name || '—' }}</button>
          <span class="row-qty">×{{ row.quantity }}</span>
          <span class="row-meta">{{ row.zone }}</span>
          <span class="spacer" />
          <button type="button" class="action" @click="openAdd(row)">Add copies…</button>
        </li>
      </ul>
    </section>

    <section v-if="boundRows.length" class="section">
      <h3 class="section-head">Bound copies</h3>
      <ul class="rows">
        <li v-for="row in boundRows" :key="`b-${row.id}`" class="row bound">
          <button
            type="button"
            class="row-card"
            @click="jumpToCard(row)"
          >{{ row.scryfall_card?.name || '—' }}</button>
          <span class="row-qty">×{{ row.quantity }}</span>
          <ConditionBadge
            v-if="row.physical_copy?.condition"
            :value="row.physical_copy.condition"
          />
          <span v-if="row.physical_copy?.foil" class="foil-mark">FOIL</span>
          <span class="row-meta" :title="row.physical_copy?.notes || ''">
            {{ describePhysical(row.physical_copy) }}
          </span>
          <span
            v-if="row.physical_copy?.review_reason === 'default_values_applied'"
            class="default-flag"
            title="Assemble applied default values — confirm or correct."
          >defaults</span>
          <span class="spacer" />
          <button type="button" class="action" @click="openEdit(row)">Edit…</button>
        </li>
      </ul>
    </section>

    <p v-if="!boundRows.length && !missingRows.length" class="empty">
      Add cards to the deck first — there's nothing to manage yet.
    </p>

    <AddCopiesModal
      v-if="addTarget && addModalProps"
      v-bind="addModalProps"
      @close="addTarget = null"
    />
    <EditPhysicalCopyModal
      v-if="editTarget"
      :entry="editTarget.entry"
      @close="editTarget = null"
    />
  </div>
</template>

<style scoped>
.physical-tab {
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.banner {
  padding: 0.75rem 1rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
.banner.ok { color: #7cb98e; }
.check { font-weight: 700; font-size: 1.1rem; }

.section {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.section-head {
  margin: 0;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-70, #a8a396);
}
.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.75rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 5px;
  font-size: 0.85rem;
}
.row.missing { border-left: 2px solid var(--amber, #c99d3d); }
.row-card {
  background: transparent;
  border: none;
  color: var(--amber, #c99d3d);
  cursor: pointer;
  padding: 0;
  font: inherit;
  text-decoration: underline dotted;
  text-align: left;
  flex: 0 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 30ch;
}
.row-qty {
  font-variant-numeric: tabular-nums;
  color: var(--ink-70, #a8a396);
  min-width: 28px;
}
.row-meta {
  font-size: 0.78rem;
  color: var(--ink-70, #a8a396);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 28ch;
}
.foil-mark {
  font-size: 0.7rem;
  color: var(--amber, #c99d3d);
  letter-spacing: 0.1em;
}
.default-flag {
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 1px 5px;
  border: 1px solid var(--amber-lo, #6e5421);
  color: var(--amber, #c99d3d);
  border-radius: 3px;
}
.spacer { flex: 1; }
.action {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 4px 10px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.78rem;
}
.action:hover { background: var(--bg-1, #1d1c1a); border-color: var(--amber, #c99d3d); color: var(--amber); }
.empty {
  font-size: 0.85rem;
  color: var(--ink-70, #a8a396);
  font-style: italic;
}
</style>
