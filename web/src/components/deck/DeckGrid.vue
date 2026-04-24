<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useDeckStore } from '../../stores/deck'
import DeckCardTile from './DeckCardTile.vue'
import DeckCardStrip from './DeckCardStrip.vue'

const props = defineProps({
  zone: { type: String, required: true },
})

const deck = useDeckStore()
const route = useRoute()

const collapsed = reactive(loadCollapseState())

const gridRef = ref(null)
const containerWidth = ref(0)
let resizeObserver = null

const COLUMN_GAP_PX = 20 // matches .deck-grid `gap: 1rem 1.25rem`

function readCssPx(name, fallback) {
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  const n = parseFloat(v)
  return Number.isFinite(n) && n > 0 ? n : fallback
}

/** Columns that fit side-by-side in the current container width. */
const fullColumnCount = computed(() => {
  if (!containerWidth.value) return 1
  const cardW = readCssPx('--card-width', 250)
  const slot  = cardW + COLUMN_GAP_PX
  return Math.max(1, Math.floor((containerWidth.value + COLUMN_GAP_PX) / slot))
})

onMounted(() => {
  if (!gridRef.value) return
  containerWidth.value = gridRef.value.clientWidth
  resizeObserver = new ResizeObserver((entries) => {
    for (const e of entries) containerWidth.value = e.contentRect.width
  })
  resizeObserver.observe(gridRef.value)
})
onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  resizeObserver = null
})

function storageKey() {
  return `vaultkeeper_deck_group_collapse::${route.params.id}`
}
function loadCollapseState() {
  try {
    return JSON.parse(localStorage.getItem(storageKey()) || '{}')
  } catch {
    return {}
  }
}
function persistCollapse() {
  try {
    localStorage.setItem(storageKey(), JSON.stringify(collapsed))
  } catch { /* ignore */ }
}
function toggle(key) {
  collapsed[key] = !collapsed[key]
  persistCollapse()
}

const WUBRG = { W: 0, U: 1, B: 2, R: 3, G: 4 }
function colorKey(card) {
  const c = card?.color_identity || []
  if (!c.length) return 'C'
  return c.slice().sort((a, b) => (WUBRG[a] ?? 99) - (WUBRG[b] ?? 99)).join('')
}
function cmcBucket(card) {
  const cmc = Number(card?.cmc) || 0
  return cmc >= 8 ? '8+' : String(cmc)
}
function typeOf(card) {
  const tl = card?.type_line || ''
  const order = ['Creature', 'Planeswalker', 'Instant', 'Sorcery', 'Artifact', 'Enchantment', 'Battle', 'Land']
  for (const t of order) if (tl.includes(t)) return t
  return 'Other'
}

const filtered = computed(() => {
  const parsed = deck.parsedView
  const nameQ = (parsed.nameQuery || '').trim().toLowerCase()
  const tokens = parsed.tokens
  const zoneEntries = deck.entriesByZone(props.zone).filter(
    (e) => !e.is_commander && !e.is_signature_spell,
  )
  if (!nameQ && tokens.length === 0) return zoneEntries
  return zoneEntries.filter((e) => {
    const card = e.scryfall_card || {}
    if (nameQ && !(card.name || '').toLowerCase().includes(nameQ)) return false
    for (const tok of tokens) {
      switch (tok.type) {
        case 'c': {
          const cardColors = (card.colors || []).map((c) => c.toLowerCase())
          if (tok.value === 'c') {
            if (cardColors.length !== 0) return false
          } else {
            if (!cardColors.includes(tok.value)) return false
          }
          break
        }
        case 't':
          if (!(card.type_line || '').toLowerCase().includes(tok.value)) return false
          break
        case 'r':
          if ((card.rarity || '').toLowerCase() !== tok.value) return false
          break
      }
    }
    return true
  })
})

const sorted = computed(() => {
  const s = deck.view.sort
  const rows = [...filtered.value]
  rows.sort((a, b) => {
    const ca = a.scryfall_card || {}
    const cb = b.scryfall_card || {}
    switch (s) {
      case 'cmc':      return (Number(ca.cmc) || 0) - (Number(cb.cmc) || 0)
      case 'color':    return colorKey(ca).localeCompare(colorKey(cb))
      case 'rarity':   return (ca.rarity || '').localeCompare(cb.rarity || '')
      case 'category': return (a.category || '').localeCompare(b.category || '')
      default:         return (ca.name || '').localeCompare(cb.name || '')
    }
  })
  return rows
})

/** Build atomic {key, label, rows} groups for any non-"full" grouping. */
function buildGroups(mode, list) {
  const map = new Map()
  for (const row of list) {
    const card = row.scryfall_card || {}
    let key = 'Other'
    if (mode === 'categories') key = row.category || 'Uncategorized'
    else if (mode === 'type')  key = typeOf(card)
    else if (mode === 'color') key = colorKey(card) || 'C'
    else if (mode === 'cmc')   key = `CMC ${cmcBucket(card)}`
    else if (mode === 'rarity')key = card.rarity || 'unknown'
    else if (mode === 'zone')  key = row.zone

    if (!map.has(key)) map.set(key, [])
    map.get(key).push(row)
  }
  return [...map.entries()]
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([key, rows]) => ({ key, label: key, rows }))
}

/**
 * Both "no grouping" and all grouped modes render as N side-by-side JS
 * flex columns. Hovering a strip expands its own card and reflows only
 * that column — the column packing is keyed by row counts (a discrete
 * integer that's stable across hover), NOT by rendered pixel height,
 * so columns never re-pack mid-hover the way CSS multi-column does.
 *
 * full     — one label-less "group" per column, chunked from the sorted
 *            list into N equal-sized slices.
 * grouped  — atomic {key,label,rows} groups distributed greedily into
 *            the shortest column so far. Adjacent same-category groups
 *            stay atomic; alphabetical ordering is loose across columns
 *            (inside a column it's preserved in insertion order).
 */
const columns = computed(() => {
  const mode = deck.view.groupBy
  const nCols = fullColumnCount.value

  if (mode === 'full') {
    const list = sorted.value
    if (list.length === 0) return []
    const perCol = Math.ceil(list.length / nCols)
    const out = []
    for (let i = 0; i < nCols; i++) {
      const rows = list.slice(i * perCol, (i + 1) * perCol)
      if (!rows.length) continue
      out.push({
        key: `col-${i}`,
        groups: [{ key: `col-${i}`, label: '', rows }],
      })
    }
    return out
  }

  const groupList = buildGroups(mode, sorted.value)
  if (groupList.length === 0) return []
  // Greedy to-shortest-column. Size = row count + 2 (approx header). We
  // only need the *relative* ordering of columns, not pixel accuracy.
  const packs = Array.from({ length: nCols }, () => ({ groups: [], size: 0 }))
  for (const g of groupList) {
    let best = packs[0]
    for (const p of packs) if (p.size < best.size) best = p
    best.groups.push(g)
    best.size += g.rows.length + 2
  }
  return packs
    .filter((p) => p.groups.length > 0)
    .map((p, i) => ({ key: `col-${i}`, groups: p.groups }))
})

const cardIllegalityMap = computed(() => deck.cardLevelIllegalitiesByScryfallId)

const gridDragActive = ref(false)
const dropTargetGroup = ref(null)

// HTML5 DnD fires dragleave on a parent whenever the cursor crosses into a
// child element (before the child's dragenter bubbles back up). A plain
// enter/leave toggle would flicker every time the cursor moves between
// strips. We debounce the "hide" side so the intervening dragenter event
// can cancel it.
let hideTimer = null
function scheduleHide() {
  if (hideTimer) clearTimeout(hideTimer)
  hideTimer = setTimeout(() => {
    gridDragActive.value = false
    dropTargetGroup.value = null
    hideTimer = null
  }, 60)
}
function cancelHide() {
  if (hideTimer) { clearTimeout(hideTimer); hideTimer = null }
}

function acceptsDrop(e) {
  return (e.dataTransfer?.types || []).includes('application/json')
}

function onGridDragEnter(e) {
  if (!acceptsDrop(e)) return
  cancelHide()
  gridDragActive.value = true
}
function onGridDragLeave() {
  scheduleHide()
}
function onGridDragOver(e) {
  if (!acceptsDrop(e)) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  // Also catches child→parent transitions where dragenter didn't bubble:
  // as long as dragover keeps firing we're still over the grid.
  cancelHide()
  gridDragActive.value = true
}

function onGroupDragEnter(e, groupKey) {
  if (!acceptsDrop(e)) return
  cancelHide()
  dropTargetGroup.value = groupKey
}
function onGroupDragOver(e, groupKey) {
  if (!acceptsDrop(e)) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  cancelHide()
  dropTargetGroup.value = groupKey
}
function onGroupDragLeave() {
  // Leaving a group only clears the column highlight; the grid's own
  // scheduleHide covers the overall fade-out when truly leaving the area.
  // We don't null the target here so moving between a group's cards
  // doesn't wipe the column hint.
}

function applyDrop(payload, groupKey) {
  if (payload.source === 'catalog') {
    deck.addEntry(deck.deck.id, {
      scryfall_id: payload.scryfall_id,
      zone: props.zone,
      category: deck.view.groupBy === 'categories' && groupKey && groupKey !== 'Uncategorized'
        ? groupKey
        : undefined,
    })
    return
  }
  if (payload.source === 'deck' && payload.deckEntryId) {
    const patch = {}
    if (props.zone && payload.zone !== props.zone) {
      patch.zone = props.zone
    }
    if (deck.view.groupBy === 'categories' && groupKey) {
      patch.category = groupKey === 'Uncategorized' ? null : groupKey
    }
    if (Object.keys(patch).length > 0) {
      deck.updateEntry(deck.deck.id, payload.deckEntryId, patch)
    }
  }
}

function parsePayload(e) {
  const raw = e.dataTransfer?.getData('application/json')
  if (!raw) return null
  try { return JSON.parse(raw) } catch { return null }
}

function resetDragState() {
  cancelHide()
  gridDragActive.value = false
  dropTargetGroup.value = null
}

function onGridDrop(e) {
  if (!acceptsDrop(e)) return
  e.preventDefault()
  resetDragState()
  const payload = parsePayload(e)
  if (!payload) return
  applyDrop(payload, null)
}

function onGroupDrop(e, groupKey) {
  if (!acceptsDrop(e)) return
  e.preventDefault()
  // Stop the grid-level handler from firing too — the group has the
  // category context we need, the grid fallback doesn't.
  e.stopPropagation()
  resetDragState()
  const payload = parsePayload(e)
  if (!payload) return
  applyDrop(payload, groupKey)
}

function onEntryClick(entry) {
  deck.setActiveEntry(entry.id)
}

function isIllegal(entry) {
  return !!cardIllegalityMap.value[entry.scryfall_id]
}

const gcFormat = computed(() => deck.deck?.format === 'commander')
</script>

<template>
  <div
    ref="gridRef"
    class="deck-grid"
    :class="{ 'drag-active': gridDragActive }"
    @dragenter="onGridDragEnter"
    @dragleave="onGridDragLeave"
    @dragover="onGridDragOver"
    @drop="onGridDrop"
  >
    <div v-for="column in columns" :key="column.key" class="deck-column">
      <div
        v-for="group in column.groups"
        :key="group.key"
        class="deck-group"
        :class="{ 'drop-target': dropTargetGroup === group.key }"
        @dragenter="onGroupDragEnter($event, group.key)"
        @dragover="onGroupDragOver($event, group.key)"
        @dragleave="onGroupDragLeave"
        @drop="onGroupDrop($event, group.key)"
      >
        <header v-if="group.label" class="group-header" @click="toggle(group.key)">
          <span class="chevron" :class="{ collapsed: collapsed[group.key] }">▾</span>
          <span>{{ group.label }}</span>
          <span class="count">({{ group.rows.reduce((s, r) => s + r.quantity, 0) }})</span>
        </header>
        <div v-if="!collapsed[group.key]" class="group-body" :class="deck.view.displayMode">
          <template v-for="entry in group.rows" :key="entry.id">
            <DeckCardTile
              v-if="deck.view.displayMode === 'tiles'"
              :entry="entry"
              :illegal="isIllegal(entry)"
              :show-game-changer="gcFormat"
              @click="onEntryClick(entry)"
            />
            <DeckCardStrip
              v-else
              :entry="entry"
              :illegal="isIllegal(entry)"
              :show-game-changer="gcFormat"
              @click="onEntryClick(entry)"
            />
          </template>
        </div>
      </div>
    </div>
    <div v-if="!columns.length" class="empty-state">
      No cards in this zone. Drop cards here from the catalog.
    </div>
  </div>
</template>

<style scoped>
/* Single layout for both "no grouping" (full) and every grouped mode:
   N side-by-side JS flex columns. Groups are atomic inside each column.
   Column assignment is keyed by row counts (stable across hover), so
   expanding a strip reflows only its own column — neighbouring columns
   never re-pack the way CSS multi-column did. */
.deck-grid {
  padding: 0.5rem 1.25rem 1.5rem;
  position: relative;
  transition: background 120ms ease, box-shadow 120ms ease;
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 1rem 1.25rem;
}
.deck-column {
  flex: 0 0 auto;
  width: var(--card-width);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
/* Whole-zone hint while a valid drag hovers anywhere in the grid. The
   empty-state pane also picks this up so side/maybe boards with no
   cards still show they're a valid target. */
.deck-grid.drag-active {
  background: rgba(201, 157, 61, 0.06);
  box-shadow: inset 0 0 0 2px rgba(201, 157, 61, 0.35);
}
.deck-group {
  min-height: 40px;
  border-radius: 6px;
  transition: background 120ms ease, outline-color 120ms ease;
  outline: 2px dashed transparent;
  outline-offset: -2px;
}
/* Precise column highlight so the user sees exactly which category/column
   the card will land in. */
.deck-group.drop-target {
  background: rgba(201, 157, 61, 0.12);
  outline-color: var(--vk-gold, #c9a552);
}
.group-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.25rem;
  border-bottom: 1px solid var(--vk-border, #33312c);
  font-size: 0.85rem;
  cursor: pointer;
  user-select: none;
}
.chevron {
  display: inline-block;
  transition: transform 120ms ease;
}
.chevron.collapsed { transform: rotate(-90deg); }
.count { color: var(--vk-fg-dim, #a8a396); }
.group-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0.4rem 0;
}
.group-body.strips {
  gap: 0;
}
.empty-state {
  flex: 1 1 100%;
  padding: 2rem;
  text-align: center;
  color: var(--vk-fg-dim, #a8a396);
  border: 1px dashed var(--vk-border, #33312c);
  border-radius: 6px;
}
</style>
