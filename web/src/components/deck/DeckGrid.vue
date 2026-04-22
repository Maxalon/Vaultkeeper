<script setup>
import { computed, reactive } from 'vue'
import { useRoute } from 'vue-router'
import { useDeckStore } from '../../stores/deck'

const props = defineProps({
  zone: { type: String, required: true },
})

const deck = useDeckStore()
const route = useRoute()

const collapsed = reactive(loadCollapseState())

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
  const q = (deck.view.search || '').trim().toLowerCase()
  const zoneEntries = deck.entriesByZone(props.zone)
  if (!q) return zoneEntries
  return zoneEntries.filter((e) =>
    (e.scryfall_card?.name || '').toLowerCase().includes(q),
  )
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
  if (mode === 'full') return [{ key: 'all', label: '', rows: sorted.value }]

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

function onDropGroup(e, groupKey) {
  e.preventDefault()
  const raw = e.dataTransfer?.getData('application/json')
  if (!raw) return
  let payload
  try { payload = JSON.parse(raw) } catch { return }

  if (payload.source === 'catalog') {
    deck.addEntry(deck.deck.id, {
      scryfall_id: payload.scryfall_id,
      zone: props.zone,
      category: deck.view.groupBy === 'categories' && groupKey !== 'Uncategorized'
        ? groupKey
        : undefined,
    })
    return
  }
  if (payload.source === 'deck' && payload.deckEntryId && deck.view.groupBy === 'categories') {
    deck.updateEntry(deck.deck.id, payload.deckEntryId, {
      category: groupKey === 'Uncategorized' ? null : groupKey,
    })
  }
}

function onDragOver(e) {
  const types = e.dataTransfer?.types || []
  if (types.includes('application/json')) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'copy'
  }
}

function entryDragStart(e, entry) {
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/json', JSON.stringify({
    source: 'deck',
    deckEntryId: entry.id,
    scryfall_id: entry.scryfall_id,
  }))
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
  <div class="deck-grid">
    <div
      v-for="group in groups"
      :key="group.key"
      class="deck-group"
      @dragover="onDragOver"
      @drop="onDropGroup($event, group.key)"
    >
      <header v-if="group.label" class="group-header" @click="toggle(group.key)">
        <span class="chevron" :class="{ collapsed: collapsed[group.key] }">▾</span>
        <span>{{ group.label }}</span>
        <span class="count">({{ group.rows.reduce((s, r) => s + r.quantity, 0) }})</span>
      </header>
      <div v-if="!collapsed[group.key]" class="group-body" :class="deck.view.displayMode">
        <div
          v-for="entry in group.rows"
          :key="entry.id"
          class="deck-card"
          :class="{ 'illegal-glow': isIllegal(entry) }"
          draggable="true"
          @click="onEntryClick(entry)"
          @dragstart="entryDragStart($event, entry)"
        >
          <img
            v-if="entry.scryfall_card?.image_small"
            :src="entry.scryfall_card.image_small"
            :alt="entry.scryfall_card.name"
          />
          <div v-else class="card-name-fallback">{{ entry.scryfall_card?.name }}</div>
          <span v-if="entry.quantity > 1" class="qty-badge">{{ entry.quantity }}</span>
          <span v-if="gcFormat && entry.scryfall_card?.commander_game_changer" class="gc-badge">GC</span>
        </div>
      </div>
    </div>
    <div v-if="!groups.length" class="empty-state">
      No cards in this zone. Drop cards here from the catalog.
    </div>
  </div>
</template>

<style scoped>
.deck-grid {
  padding: 0.5rem 1.25rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.deck-group { min-height: 40px; }
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
  flex-wrap: wrap;
  gap: 6px;
  padding: 0.4rem 0;
}
.group-body.tiles .deck-card { width: 90px; aspect-ratio: 63/88; }
.group-body.strips .deck-card {
  width: 110px;
  height: 24px;
  overflow: hidden;
}
.deck-card {
  position: relative;
  border-radius: 4px;
  overflow: hidden;
  cursor: pointer;
  background: #1a1a22;
  border: 1px solid #0a0a0a;
}
.deck-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
.group-body.strips .deck-card img { object-position: top; }
.card-name-fallback {
  padding: 4px;
  font-size: 10px;
  color: var(--vk-fg-dim, #a8a396);
}
.qty-badge, .gc-badge {
  position: absolute;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 999px;
}
.qty-badge {
  top: 2px; right: 2px;
  background: rgba(0, 0, 0, 0.7);
  color: #fff;
}
.gc-badge {
  bottom: 2px; left: 2px;
}
.empty-state {
  padding: 2rem;
  text-align: center;
  color: var(--vk-fg-dim, #a8a396);
  border: 1px dashed var(--vk-border, #33312c);
  border-radius: 6px;
}
</style>
