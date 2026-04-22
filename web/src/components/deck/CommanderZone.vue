<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'
import CommanderStrip from './CommanderStrip.vue'

const deck = useDeckStore()
const tabs = useTabsStore()

const format = computed(() => deck.deck?.format)
const slot1 = computed(() => deck.deck?.commander1 || null)
const slot2 = computed(() => deck.deck?.commander2 || null)
const companion = computed(() => deck.deck?.companion || null)

function isBackground(card) {
  return (card?.subtypes || []).includes('Background')
    || (card?.type_line || '').includes('Background')
}
function hasChooseBackground(card) {
  return (card?.keywords || []).includes('Choose a background')
}

const cardIllegal = computed(() => deck.cardLevelIllegalitiesByScryfallId)

function isIllegal(scryId) {
  return !!cardIllegal.value[scryId]
}

const mode = computed(() => {
  if (!format.value) return 'none'
  if (['standard', 'modern', 'pauper'].includes(format.value)) return 'none'
  if (format.value === 'oathbreaker') return 'oathbreaker'
  const s1 = slot1.value, s2 = slot2.value
  if (s1 && s2 && hasChooseBackground(s1) && isBackground(s2)) {
    return 'background'
  }
  return 'commander'
})

const signatureSpells = computed(() =>
  deck.signatureSpellEntries.map((e) => ({
    card: e.scryfall_card,
    parentEntryId: e.signature_for_entry_id,
  })),
)

function onCommanderClick(card) {
  if (!card) return
  const entry = deck.entries.find((e) => e.scryfall_id === card.scryfall_id)
  if (entry) deck.setActiveEntry(entry.id)
}

function onAddCommander() {
  tabs.openTab('catalog', { prefilledQuery: 'is:commander' })
}
function onAddCompanion() {
  tabs.openTab('catalog', { prefilledQuery: 'keyword:Companion' })
}
</script>

<template>
  <section v-if="mode !== 'none'" class="commander-zone">
    <!-- Standard commander layout (two tiles or single) -->
    <template v-if="mode === 'commander'">
      <div class="commander-column">
        <CommanderStrip
          v-if="companion"
          :card="companion"
          label="Companion"
          @click="onCommanderClick(companion)"
        />
        <div
          v-if="slot1"
          class="commander-tile"
          :class="{ 'illegal-glow': isIllegal(slot1.scryfall_id) }"
          @click="onCommanderClick(slot1)"
        >
          <img v-if="slot1.image_small" :src="slot1.image_small" :alt="slot1.name" />
        </div>
        <button v-else type="button" class="commander-placeholder" @click="onAddCommander">
          + Add Commander
        </button>
      </div>
      <div class="commander-column">
        <div
          v-if="slot2"
          class="commander-tile"
          :class="{ 'illegal-glow': isIllegal(slot2.scryfall_id) }"
          @click="onCommanderClick(slot2)"
        >
          <img v-if="slot2.image_small" :src="slot2.image_small" :alt="slot2.name" />
        </div>
        <button v-else type="button" class="commander-placeholder" @click="onAddCommander">
          + Partner
        </button>
      </div>
    </template>

    <!-- Background + "choose a background" layout: background above cmdr1 -->
    <template v-else-if="mode === 'background'">
      <div class="commander-column">
        <CommanderStrip v-if="companion" :card="companion" label="Companion" />
        <CommanderStrip :card="slot2" label="Background" />
        <div
          class="commander-tile"
          :class="{ 'illegal-glow': isIllegal(slot1.scryfall_id) }"
          @click="onCommanderClick(slot1)"
        >
          <img v-if="slot1.image_small" :src="slot1.image_small" :alt="slot1.name" />
        </div>
      </div>
    </template>

    <!-- Oathbreaker: signature spell above the planeswalker -->
    <template v-else-if="mode === 'oathbreaker'">
      <div
        v-for="(spell, i) in signatureSpells"
        :key="spell.card?.scryfall_id || i"
        class="commander-column"
      >
        <CommanderStrip :card="spell.card" label="Signature spell" />
      </div>
      <div v-if="slot1" class="commander-column">
        <div class="commander-tile" @click="onCommanderClick(slot1)">
          <img v-if="slot1.image_small" :src="slot1.image_small" :alt="slot1.name" />
        </div>
      </div>
      <button v-else type="button" class="commander-placeholder" @click="onAddCommander">
        + Oathbreaker
      </button>
    </template>

    <button
      v-if="mode === 'commander' && !companion"
      type="button"
      class="companion-add-btn"
      @click="onAddCompanion"
    >+ Add Companion</button>
  </section>
</template>

<style scoped>
.commander-zone {
  display: flex;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  align-items: flex-start;
}
.commander-column {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 150px;
}
.commander-tile {
  aspect-ratio: 63 / 88;
  border-radius: 6px;
  overflow: hidden;
  cursor: pointer;
  background: #1a1a22;
  border: 1px solid #0a0a0a;
}
.commander-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.commander-placeholder {
  aspect-ratio: 63 / 88;
  border: 1px dashed var(--vk-border, #33312c);
  background: transparent;
  color: var(--vk-fg-dim, #a8a396);
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  width: 150px;
}
.commander-placeholder:hover {
  border-color: var(--vk-accent, #c99d3d);
  color: var(--vk-fg, #e9e4d6);
}
.companion-add-btn {
  align-self: flex-start;
  background: transparent;
  border: 1px dashed var(--vk-border, #33312c);
  color: var(--vk-fg-dim, #a8a396);
  padding: 0.3rem 0.6rem;
  border-radius: 4px;
  font-size: 0.75rem;
  cursor: pointer;
}
</style>
