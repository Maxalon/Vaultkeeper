<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import CommanderStrip from './CommanderStrip.vue'
import EntryActionsMenu from './EntryActionsMenu.vue'

const deck = useDeckStore()

const menuEntry = ref(null)
const menuPos = ref(null)

function onContextMenu(e, card) {
  if (!card) return
  const entry = deck.entries.find((x) => x.scryfall_id === card.scryfall_id)
  if (!entry) return
  e.preventDefault()
  menuEntry.value = entry
  menuPos.value = { x: e.clientX, y: e.clientY }
}
function closeMenu() {
  menuPos.value = null
  menuEntry.value = null
}

async function onSwap() {
  if (!deck.deck?.id) return
  try { await deck.swapCommanders(deck.deck.id) } catch { /* toasted */ }
}

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

const hasContent = computed(
  () => !!(slot1.value || slot2.value || companion.value || signatureSpells.value.length),
)

function onCommanderClick(card) {
  if (!card) return
  const entry = deck.entries.find((e) => e.scryfall_id === card.scryfall_id)
  if (entry) deck.setActiveEntry(entry.id)
}
</script>

<template>
  <section v-if="mode !== 'none' && hasContent" class="commander-zone">
    <!-- Standard commander layout (two tiles or single) -->
    <template v-if="mode === 'commander'">
      <div v-if="slot1 || companion" class="commander-column">
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
          @contextmenu="onContextMenu($event, slot1)"
        >
          <img
            v-if="slot1.image_normal || slot1.image_small"
            :src="slot1.image_normal || slot1.image_small"
            :alt="slot1.name"
          />
        </div>
      </div>
      <button
        v-if="slot1 && slot2"
        type="button"
        class="swap-btn"
        title="Swap commander 1 and commander 2"
        @click="onSwap"
      >⇄</button>
      <div v-if="slot2" class="commander-column">
        <div
          class="commander-tile"
          :class="{ 'illegal-glow': isIllegal(slot2.scryfall_id) }"
          @click="onCommanderClick(slot2)"
          @contextmenu="onContextMenu($event, slot2)"
        >
          <img
            v-if="slot2.image_normal || slot2.image_small"
            :src="slot2.image_normal || slot2.image_small"
            :alt="slot2.name"
          />
        </div>
      </div>
    </template>

    <!-- Background + "choose a background" layout: background above cmdr1 -->
    <template v-else-if="mode === 'background'">
      <div class="commander-column">
        <CommanderStrip
          v-if="companion"
          :card="companion"
          label="Companion"
          @click="onCommanderClick(companion)"
        />
        <CommanderStrip
          :card="slot2"
          label="Background"
          @click="onCommanderClick(slot2)"
          @contextmenu="onContextMenu($event, slot2)"
        />
        <div
          class="commander-tile"
          :class="{ 'illegal-glow': isIllegal(slot1.scryfall_id) }"
          @click="onCommanderClick(slot1)"
          @contextmenu="onContextMenu($event, slot1)"
        >
          <img
            v-if="slot1.image_normal || slot1.image_small"
            :src="slot1.image_normal || slot1.image_small"
            :alt="slot1.name"
          />
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
        <CommanderStrip
          :card="spell.card"
          label="Signature spell"
          @click="onCommanderClick(spell.card)"
          @contextmenu="onContextMenu($event, spell.card)"
        />
      </div>
      <div v-if="slot1" class="commander-column">
        <div
          class="commander-tile"
          @click="onCommanderClick(slot1)"
          @contextmenu="onContextMenu($event, slot1)"
        >
          <img
            v-if="slot1.image_normal || slot1.image_small"
            :src="slot1.image_normal || slot1.image_small"
            :alt="slot1.name"
          />
        </div>
      </div>
    </template>

    <EntryActionsMenu :entry="menuEntry" :position="menuPos" @close="closeMenu" />
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
  width: 220px;
}
.commander-tile {
  aspect-ratio: 63 / 88;
  /* Percentage-based radius matches the Scryfall card's own corner
     curve so the dark tile doesn't peek through as a square corner. */
  border-radius: 4.5% / 3.2%;
  overflow: hidden;
  cursor: pointer;
  background: #1a1a22;
  border: 1px solid #0a0a0a;
}
.commander-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.swap-btn {
  align-self: center;
  margin-top: 80px;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  color: var(--ink-70, #a8a396);
  width: 28px;
  height: 28px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 0.95rem;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.swap-btn:hover {
  background: var(--bg-2-sunken, #1c1a16);
  color: var(--amber, #c9a552);
  border-color: #8a6d2e;
}
</style>
