<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useCollectionStore, parseSearch, serializeQuery } from '../stores/collection'
import VaultMark from './VaultMark.vue'
import FilterChip from './FilterChip.vue'
import SyntaxSearch from './SyntaxSearch.vue'

const props = defineProps({
  sidebarCollapsed: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle-sidebar'])

const collection = useCollectionStore()
const router = useRouter()

// ── Chip option lists ────────────────────────────────────────────────────
const COLOR_OPTS = [
  { k: '', n: 'Any color' },
  { k: 'w', n: 'White' },
  { k: 'u', n: 'Blue' },
  { k: 'b', n: 'Black' },
  { k: 'r', n: 'Red' },
  { k: 'g', n: 'Green' },
  { k: 'c', n: 'Colorless' },
]
const TYPE_OPTS = [
  { k: '', n: 'Any type' },
  { k: 'creature', n: 'Creature' },
  { k: 'instant', n: 'Instant' },
  { k: 'sorcery', n: 'Sorcery' },
  { k: 'artifact', n: 'Artifact' },
  { k: 'enchantment', n: 'Enchantment' },
  { k: 'planeswalker', n: 'Planeswalker' },
  { k: 'land', n: 'Land' },
]
const RARITY_OPTS = [
  { k: '', n: 'Any rarity' },
  { k: 'common', n: 'Common' },
  { k: 'uncommon', n: 'Uncommon' },
  { k: 'rare', n: 'Rare' },
  { k: 'mythic', n: 'Mythic' },
]
const SORT_OPTS = [
  { k: 'name', n: 'Name' },
  { k: 'set_code', n: 'Set' },
  { k: 'rarity', n: 'Rarity' },
  { k: 'collector_number', n: 'Number' },
  { k: 'condition', n: 'Condition' },
  { k: 'color', n: 'Color' },
]

// Build the Set chip's option list from sets actually present in the
// loaded collection. Limits noise from sets the user doesn't own.
const SET_OPTS = computed(() => {
  const seen = new Set()
  for (const e of collection.entries) {
    const code = e.card?.set_code
    if (code) seen.add(code)
  }
  const opts = [{ k: '', n: 'Any set' }]
  for (const code of [...seen].sort()) {
    opts.push({ k: code.toLowerCase(), n: code.toUpperCase() })
  }
  return opts
})

// ── Query state — chips and syntax search round-trip the same string ────
const parsed = computed(() => parseSearch(collection.filters.search))

function setChip(key, val) {
  const next = {
    free: parsed.value.nameQuery,
    chips: { ...parsed.value.chips, [key]: val },
    sort: parsed.value.sort,
  }
  collection.filters.search = serializeQuery(next)
  scheduleFetch()
}

function setSort(val) {
  collection.filters.sort = val
  collection.fetchEntries()
}

let debounceTimer = null
function scheduleFetch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => collection.fetchEntries(), 250)
}

function onSearchInput(v) {
  collection.filters.search = v
  scheduleFetch()
}

function openSettings() {
  router.push('/settings')
}
</script>

<template>
  <header class="vk-topbar">
    <div class="vk-topbar-brand">
      <VaultMark />
      <button
        class="vk-sidebar-collapse"
        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="emit('toggle-sidebar')"
      >
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1.5" y="2" width="11" height="10" rx="1.5" />
          <line x1="5.5" y1="2" x2="5.5" y2="12" />
          <path v-if="sidebarCollapsed" d="M8 5l2 2-2 2" />
          <path v-else d="M10 5l-2 2 2 2" />
        </svg>
      </button>
    </div>

    <div class="vk-topbar-center">
      <FilterChip
        label="Color"
        :value="parsed.chips.c"
        :options="COLOR_OPTS"
        token-class="tok-c"
        hint-prefix="c:"
        @change="(v) => setChip('c', v)"
      />
      <FilterChip
        label="Type"
        :value="parsed.chips.t"
        :options="TYPE_OPTS"
        token-class="tok-t"
        hint-prefix="t:"
        @change="(v) => setChip('t', v)"
      />
      <FilterChip
        label="Rarity"
        :value="parsed.chips.r"
        :options="RARITY_OPTS"
        token-class="tok-r"
        hint-prefix="r:"
        @change="(v) => setChip('r', v)"
      />
      <FilterChip
        label="Set"
        :value="parsed.chips.s"
        :options="SET_OPTS"
        token-class="tok-set"
        hint-prefix="set:"
        @change="(v) => setChip('s', v)"
      />
      <SyntaxSearch
        :model-value="collection.filters.search"
        @update:model-value="onSearchInput"
      />
    </div>

    <div class="vk-topbar-right">
      <FilterChip
        label="Sort"
        :value="collection.filters.sort"
        :options="SORT_OPTS"
        token-class="tok-sort"
        align="right"
        hint-prefix="sort:"
        @change="setSort"
      />
      <button
        class="vk-chip"
        style="width: 32px; padding: 0; justify-content: center;"
        title="Display options"
        @click="openSettings"
      >
        <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
          <circle cx="6" cy="2.5" r="1" />
          <circle cx="6" cy="6" r="1" />
          <circle cx="6" cy="9.5" r="1" />
        </svg>
      </button>
      <button
        class="vk-btn vk-btn-primary"
        :class="{ active: collection.selecting }"
        @click="collection.toggleSelecting()"
      >Select</button>
    </div>
  </header>
</template>

<style scoped>
.vk-topbar {
  display: grid;
  grid-template-columns: var(--sidebar-width) 1fr auto;
  align-items: center;
  border-bottom: 1px solid var(--vk-line);
  background: var(--vk-bg-1);
  height: 56px;
  flex-shrink: 0;
}

.vk-topbar-brand {
  padding: 0 14px 0 20px;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-right: 1px solid var(--vk-line);
  overflow: hidden;
}

.vk-sidebar-collapse {
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: 1px solid color-mix(in oklab, var(--vk-gold) 35%, var(--vk-line));
  border-radius: 6px;
  color: var(--vk-gold);
  cursor: pointer;
  transition: all 120ms ease;
  padding: 0;
}
.vk-sidebar-collapse:hover {
  color: #1a1408;
  background: var(--vk-gold);
  border-color: var(--vk-gold);
}

.vk-topbar-center {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 16px;
  height: 100%;
}

.vk-topbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 16px;
  height: 100%;
}
</style>
