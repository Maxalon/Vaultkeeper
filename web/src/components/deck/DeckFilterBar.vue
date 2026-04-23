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

function onDirective(key, v) {
  deck.setDeckDirective(key, v)
}

function onSearch(v) {
  deck.view.search = v
}
</script>

<template>
  <div class="deck-filter-bar">
    <div class="deck-filter-row search-row">
      <SyntaxSearch
        :model-value="deck.view.search"
        placeholder="Search: c:red t:creature r:mythic…"
        @update:model-value="onSearch"
      />
    </div>
    <div class="deck-filter-row controls-row">
      <div class="filter-group">
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
      </div>
      <div class="display-group">
        <FilterChip
          label="Group"
          :value="deck.view.groupBy"
          :options="GROUP_OPTS"
          token-class="tok-group"
          @change="(v) => onDirective('group', v)"
        />
        <FilterChip
          label="Sort"
          :value="deck.view.sort"
          :options="SORT_OPTS"
          token-class="tok-sort"
          @change="(v) => onDirective('sort', v)"
        />
        <FilterChip
          label="View"
          :value="deck.view.displayMode"
          :options="DISPLAY_OPTS"
          token-class="tok-display"
          align="right"
          @change="(v) => onDirective('display', v)"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.deck-filter-bar {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--vk-line, #33312c);
  background: var(--vk-bg-1, #1d1c1a);
}

.deck-filter-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.controls-row {
  flex-wrap: wrap;
}

.filter-group,
.display-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.display-group {
  margin-left: auto;
  padding-left: 12px;
  border-left: 1px solid var(--vk-line, #33312c);
}
</style>
