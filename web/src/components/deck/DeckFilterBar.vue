<script setup>
import { useDeckStore } from '../../stores/deck'

const deck = useDeckStore()

let searchDebounce = null
function onSearchInput(e) {
  const value = e.target.value
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    deck.view.search = value
  }, 150)
}
</script>

<template>
  <div class="deck-filter-bar">
    <input
      type="search"
      class="deck-search"
      :value="deck.view.search"
      placeholder="Search…"
      @input="onSearchInput"
    />

    <label class="filter-label">
      Group:
      <select v-model="deck.view.groupBy">
        <option value="full">No grouping</option>
        <option value="categories">Categories</option>
        <option value="type">Type</option>
        <option value="color">Color</option>
        <option value="cmc">CMC</option>
        <option value="rarity">Rarity</option>
        <option value="zone">Zone</option>
      </select>
    </label>

    <label class="filter-label">
      Sort:
      <select v-model="deck.view.sort">
        <option value="name">Name</option>
        <option value="cmc">CMC</option>
        <option value="color">Color</option>
        <option value="rarity">Rarity</option>
        <option value="category">Category</option>
      </select>
    </label>

    <label class="filter-label">
      <select v-model="deck.view.displayMode">
        <option value="strips">Strips</option>
        <option value="tiles">Tiles</option>
      </select>
    </label>
  </div>
</template>

<style scoped>
.deck-filter-bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1.25rem;
  border-bottom: 1px solid var(--vk-border, #33312c);
  font-size: 0.85rem;
  flex-wrap: wrap;
}
.deck-search {
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  border-radius: 4px;
  color: inherit;
  padding: 0.35rem 0.6rem;
  min-width: 200px;
  font-size: 0.85rem;
}
.filter-label {
  color: var(--vk-fg-dim, #a8a396);
  display: inline-flex;
  gap: 0.3rem;
  align-items: center;
}
.filter-label select {
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  color: inherit;
  padding: 0.3rem 0.4rem;
  border-radius: 4px;
  font-size: 0.85rem;
}
</style>
