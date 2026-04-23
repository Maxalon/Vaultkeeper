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

const groups = computed(() => {
  const mode = deck.view.groupBy
  if (mode === 'full') {
    // Split the deck into N independent columns by chunking the sorted
    // list, N from fullColumnCount. Each chunk renders as its own
    // flex-column group, so hovering a strip (which expands that card's
    // height) only grows its own column — neighbouring columns do not
    // reflow. Adding/removing/filtering/sorting or resizing the viewport
    // still redistributes across columns, because those change either
    // the list or the column count.
    const list = sorted.value
    const cols = fullColumnCount.value
    if (list.length === 0) return []
    const perCol = Math.ceil(list.length / cols)
    const out = []
    for (let i = 0; i < cols; i++) {
      const rows = list.slice(i * perCol, (i + 1) * perCol)
      if (!rows.length) continue
      out.push({ key: `col-${i}`, label: '', rows })
    }
    return out
  }

  const map = new Map()
  for (const row of sorted.value) {
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
  e.preventDefault()
  resetDragState()
  const payload = parsePayload(e)
  if (!payload) return
  applyDrop(payload, null)
}

function onGroupDrop(e, groupKey) {
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
    <div
      v-for="group in groups"
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
    <div v-if="!groups.length" class="empty-state">
      No cards in this zone. Drop cards here from the catalog.
    </div>
  </div>
</template>

<style scoped>
/* Category columns — each group is a one-card-wide column; columns flow
   left-to-right and wrap to the next row when the viewport runs out of
   horizontal space. Mirrors Moxfield/Archidekt's column layout. */
.deck-grid {
  padding: 0.5rem 1.25rem 1.5rem;
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 1rem 1.25rem;
  position: relative;
  transition: background 120ms ease, box-shadow 120ms ease;
}
/* Whole-zone hint while a valid drag hovers anywhere in the grid. The
   empty-state pane also picks this up so side/maybe boards with no
   cards still show they're a valid target. */
.deck-grid.drag-active {
  background: rgba(201, 157, 61, 0.06);
  box-shadow: inset 0 0 0 2px rgba(201, 157, 61, 0.35);
}
.deck-group {
  flex: 0 0 auto;
  width: var(--card-width);
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
