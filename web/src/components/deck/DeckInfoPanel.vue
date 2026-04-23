<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'
import ManaSymbol from '../ManaSymbol.vue'
import ExportMenu from './ExportMenu.vue'

const deck = useDeckStore()
const tabs = useTabsStore()

const emit = defineEmits(['edit', 'export'])

const mainCount = computed(() =>
  deck.entriesByZone('main').reduce((sum, e) => sum + (e.quantity || 0), 0),
)

const identityLetters = computed(() => {
  const src = deck.deck?.color_identity || ''
  return typeof src === 'string' ? src.split('') : Array.isArray(src) ? src : []
})

function onDeckNameClick() {
  if (deck.hasDeckLevelIllegality) {
    tabs.openTab('illegalities')
  }
}
</script>

<template>
  <section v-if="deck.deck" class="deck-info-panel">
    <header class="deck-header">
      <h2
        class="deck-name"
        :class="{ 'illegal-glow': deck.hasDeckLevelIllegality }"
        @click="onDeckNameClick"
      >{{ deck.deck.name }}</h2>
      <span class="format-badge">{{ deck.deck.format }}</span>
      <div class="header-actions">
        <button type="button" class="action-btn" @click="emit('edit')">Edit</button>
        <ExportMenu @select="(payload) => emit('export', payload)" />
      </div>
    </header>

    <div class="deck-meta">
      <span class="pips">
        <ManaSymbol
          v-for="letter in identityLetters"
          :key="letter"
          :symbol="`{${letter}}`"
        />
        <span v-if="!identityLetters.length" class="pips-empty">colorless</span>
      </span>
      <span class="count">{{ mainCount }} cards</span>
    </div>

    <p v-if="deck.deck.description" class="deck-desc">{{ deck.deck.description }}</p>
  </section>
</template>

<style scoped>
.deck-info-panel {
  padding: 1rem 1.25rem 0.5rem;
  border-bottom: 1px solid var(--vk-border, #33312c);
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.deck-header {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
}
.deck-name {
  margin: 0;
  font-family: var(--font-serif), 'Newsreader', serif;
  font-size: 1.7rem;
  font-weight: 500;
  line-height: 1.1;
  cursor: default;
  padding: 2px 4px;
  border-radius: 4px;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1 1 auto;
}
.header-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex: 0 0 auto;
  align-self: center;
}
.deck-name.illegal-glow { cursor: pointer; }
.format-badge {
  text-transform: uppercase;
  font-size: 0.72rem;
  letter-spacing: 0.05em;
  color: var(--vk-fg-dim, #a8a396);
  background: var(--vk-surface-raised, #26241f);
  padding: 2px 8px;
  border-radius: 999px;
}
.deck-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.85rem;
  color: var(--vk-fg-dim, #a8a396);
}
.pips { display: inline-flex; align-items: center; gap: 2px; }
.pips-empty { font-style: italic; }
.action-btn {
  background: transparent;
  border: 1px solid var(--vk-border, #33312c);
  color: inherit;
  padding: 0.25rem 0.6rem;
  font-size: 0.8rem;
  cursor: pointer;
  border-radius: 4px;
}
.action-btn:hover { background: var(--vk-surface-raised, #26241f); }
.deck-desc {
  margin: 0;
  color: var(--vk-fg-dim, #a8a396);
  font-size: 0.85rem;
  max-height: 3em;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
</style>
