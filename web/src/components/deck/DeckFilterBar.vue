<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import FilterChip from '../FilterChip.vue'
import SyntaxSearch from '../SyntaxSearch.vue'

const deck = useDeckStore()

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
const GROUP_OPTS = [
  { k: 'full', n: 'No grouping' },
  { k: 'categories', n: 'Categories' },
  { k: 'type', n: 'Type' },
  { k: 'color', n: 'Color' },
  { k: 'cmc', n: 'CMC' },
  { k: 'rarity', n: 'Rarity' },
  { k: 'zone', n: 'Zone' },
]
const SORT_OPTS = [
  { k: 'name', n: 'Name' },
  { k: 'cmc', n: 'CMC' },
  { k: 'color', n: 'Color' },
  { k: 'rarity', n: 'Rarity' },
  { k: 'category', n: 'Category' },
]
const DISPLAY_OPTS = [
  { k: 'strips', n: 'Strips' },
  { k: 'tiles', n: 'Tiles' },
]

const parsed = computed(() => deck.parsedView)

function onChip(key, v) {
  deck.setDeckChip(key, v)
}

function onSearch(v) {
  deck.view.search = v
}
</script>

<template>
  <div class="deck-filter-bar">
    <FilterChip
      label="Color"
      :value="parsed.chips.c"
      :options="COLOR_OPTS"
      token-class="tok-c"
      hint-prefix="c:"
      @change="(v) => onChip('c', v)"
    />
    <FilterChip
      label="Type"
      :value="parsed.chips.t"
      :options="TYPE_OPTS"
      token-class="tok-t"
      hint-prefix="t:"
      @change="(v) => onChip('t', v)"
    />
    <FilterChip
      label="Rarity"
      :value="parsed.chips.r"
      :options="RARITY_OPTS"
      token-class="tok-r"
      hint-prefix="r:"
      @change="(v) => onChip('r', v)"
    />
    <FilterChip
      label="Group"
      :value="parsed.directives.group"
      :options="GROUP_OPTS"
      token-class="tok-group"
      hint-prefix="group:"
      @change="(v) => onChip('group', v)"
    />
    <FilterChip
      label="Sort"
      :value="parsed.directives.sort"
      :options="SORT_OPTS"
      token-class="tok-sort"
      hint-prefix="sort:"
      @change="(v) => onChip('sort', v)"
    />
    <FilterChip
      label="View"
      :value="parsed.directives.display"
      :options="DISPLAY_OPTS"
      token-class="tok-display"
      hint-prefix="display:"
      @change="(v) => onChip('display', v)"
    />
    <SyntaxSearch
      :model-value="deck.view.search"
      @update:model-value="onSearch"
    />
  </div>
</template>

<style scoped>
.deck-filter-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--vk-line, #33312c);
  background: var(--vk-bg-1, #1d1c1a);
  flex-wrap: wrap;
}
</style>
