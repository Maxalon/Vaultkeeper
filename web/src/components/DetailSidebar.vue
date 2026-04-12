<script setup>
import { computed, ref, h } from 'vue'
import { useCollectionStore } from '../stores/collection'
import SetSymbol from './SetSymbol.vue'
import ManaCost from './ManaCost.vue'
import ManaSymbol from './ManaSymbol.vue'

const collection = useCollectionStore()
const flipped = ref(false)

const entry = computed(() => collection.activeEntry)
const card = computed(() => entry.value?.card || null)

const isDfc = computed(() => !!card.value?.is_dfc)
const showBack = computed(() => isDfc.value && flipped.value)

const displayedImage = computed(() => {
  if (!card.value) return null
  if (showBack.value) return card.value.image_large_back || card.value.image_normal_back
  return card.value.image_large || card.value.image_normal
})
const displayedManaCost = computed(() =>
  showBack.value ? card.value?.mana_cost_back : card.value?.mana_cost,
)
const displayedTypeLine = computed(() =>
  showBack.value ? card.value?.type_line_back : card.value?.type_line,
)
const displayedOracle = computed(() =>
  showBack.value ? card.value?.oracle_text_back : card.value?.oracle_text,
)

// Tokenize oracle text into spans + ManaSymbol components.
const OracleText = {
  props: ['text'],
  setup(p) {
    return () => {
      if (!p.text) return null
      const tokens = p.text.split(/(\{[^}]+\})/g).filter(Boolean)
      return h(
        'div',
        { class: 'oracle' },
        tokens.flatMap((tok, i) => {
          if (/^\{[^}]+\}$/.test(tok)) return [h(ManaSymbol, { key: i, symbol: tok })]
          // Preserve newlines
          return tok.split(/\n+/).flatMap((line, li, arr) => {
            const out = [h('span', { key: `${i}-${li}` }, line)]
            if (li < arr.length - 1) out.push(h('br', { key: `${i}-${li}-br` }))
            return out
          })
        }),
      )
    }
  },
}

const FORMATS = [
  ['standard', 'Standard'],
  ['pioneer', 'Pioneer'],
  ['modern', 'Modern'],
  ['legacy', 'Legacy'],
  ['vintage', 'Vintage'],
  ['commander', 'Commander'],
  ['pauper', 'Pauper'],
]

const rarityLabel = computed(() => {
  const r = card.value?.rarity || ''
  return r ? r[0].toUpperCase() + r.slice(1) : ''
})

const ptOrLoyalty = computed(() => {
  if (!card.value) return null
  if (card.value.loyalty != null && card.value.loyalty !== '') return { kind: 'L', value: card.value.loyalty }
  if (card.value.power != null && card.value.power !== '' && card.value.toughness != null) {
    return { kind: 'PT', value: `${card.value.power}/${card.value.toughness}` }
  }
  return null
})

async function patch(payload) {
  if (!entry.value) return
  await collection.updateEntry(entry.value.id, payload)
}

function onConditionChange(e) {
  patch({ condition: e.target.value })
}
function onLocationChange(e) {
  const val = e.target.value
  patch({ location_id: val === '' ? null : Number(val) })
}
function onFoilChange(e) {
  patch({ foil: e.target.checked })
}

function close() {
  collection.closeActiveEntry()
}

const realLocations = computed(() => collection.locations)
</script>

<template>
  <aside v-if="entry" class="detail-sidebar">
    <button class="close-btn" @click="close" title="Close">×</button>

    <div v-if="collection.detailLoading" class="loading">Loading…</div>
    <template v-else-if="card">
      <div class="image-wrap">
        <img
          :src="displayedImage || '/storage/card-back.jpg'"
          :alt="card.name"
          class="big-image"
          :class="{ flipping: showBack }"
        />
        <button v-if="isDfc" class="flip-btn" type="button" @click="flipped = !flipped" title="Flip card">
          ↺
        </button>
      </div>

      <h2 class="display name">{{ card.name }}</h2>

      <div class="meta">
        <SetSymbol :set="card.set_code" :rarity="card.rarity || 'common'" :size="22" />
        <span>{{ card.set_code?.toUpperCase() }} #{{ card.collector_number }}</span>
        <span class="dot">·</span>
        <span>{{ rarityLabel }}</span>
      </div>

      <div v-if="displayedManaCost" class="mana-row">
        <ManaCost :cost="displayedManaCost" />
      </div>

      <div class="type-line">{{ displayedTypeLine }}</div>

      <component :is="OracleText" :text="displayedOracle" />

      <div v-if="ptOrLoyalty" class="pt">
        <span v-if="ptOrLoyalty.kind === 'L'">Loyalty: {{ ptOrLoyalty.value }}</span>
        <span v-else>{{ ptOrLoyalty.value }}</span>
      </div>

      <hr />

      <div class="controls">
        <label class="control">
          <span>Condition</span>
          <select id="detail-condition" :value="entry.condition" @change="onConditionChange">
            <option>NM</option>
            <option>LP</option>
            <option>MP</option>
            <option>HP</option>
            <option>DMG</option>
          </select>
        </label>

        <label class="control">
          <span>Location</span>
          <select id="detail-location" :value="entry.location_id ?? ''" @change="onLocationChange">
            <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
          </select>
        </label>

        <label class="control inline">
          <input id="detail-foil" type="checkbox" :checked="entry.foil" @change="onFoilChange" />
          <span>Foil</span>
        </label>
      </div>

      <div v-if="entry.wanted_by_decks?.length" class="wanted">
        <span class="warn">⚠</span>
        <div>
          <div class="wanted-title">Wanted by decks</div>
          <ul>
            <li v-for="(name, i) in entry.wanted_by_decks" :key="i">{{ name }}</li>
          </ul>
        </div>
      </div>

      <div v-if="card.legalities" class="legalities">
        <h3>Legalities</h3>
        <div class="legality-grid">
          <template v-for="[key, label] in FORMATS" :key="key">
            <div class="leg-format">{{ label }}</div>
            <div class="leg-status" :class="`leg-${(card.legalities[key] || 'unknown').replace('_', '-')}`">
              {{ (card.legalities[key] || '—').replace('_', ' ') }}
            </div>
          </template>
        </div>
      </div>
    </template>
  </aside>
</template>

<style scoped>
.detail-sidebar {
  position: relative;
  background: var(--bg-1);
  border-left: 1px solid var(--border);
  height: 100vh;
  overflow-y: auto;
  padding: 22px 22px 60px;
}
.close-btn {
  position: absolute;
  top: 10px;
  right: 10px;
  background: transparent;
  border: none;
  color: var(--text-dim);
  font-size: 24px;
  width: 32px;
  height: 32px;
  padding: 0;
  border-radius: 50%;
}
.close-btn:hover {
  background: var(--bg-2);
  color: var(--text);
  border-color: transparent;
}
.loading {
  color: var(--text-dim);
  text-align: center;
  padding: 60px 0;
  font-style: italic;
}
.image-wrap {
  position: relative;
  display: flex;
  justify-content: center;
  margin-bottom: 14px;
  margin-top: 18px;
}
.big-image {
  width: 100%;
  max-width: 340px;
  border-radius: 12px;
  box-shadow: 0 14px 30px rgba(0, 0, 0, 0.6);
  transition: transform 400ms ease;
}
.big-image.flipping {
  transform: scaleX(-1) rotate(180deg);
}
.flip-btn {
  position: absolute;
  bottom: 10px;
  right: 10%;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.7);
  border: 1px solid var(--gold-dim);
  color: var(--gold);
  font-size: 18px;
  padding: 0;
}
.flip-btn:hover {
  background: var(--gold);
  color: var(--bg-0);
}
.name {
  font-size: 24px;
  color: var(--gold);
  margin-bottom: 6px;
  text-align: center;
}
.meta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-dim);
  margin-bottom: 10px;
}
.dot { color: var(--text-faint); }
.mana-row {
  font-size: 18px;
  text-align: center;
  margin-bottom: 8px;
}
.type-line {
  text-align: center;
  font-size: 14px;
  color: var(--text);
  margin-bottom: 12px;
  font-style: italic;
}
:deep(.oracle) {
  font-size: 14px;
  line-height: 1.55;
  color: var(--text);
  background: var(--bg-0);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 12px 14px;
  margin-bottom: 10px;
  white-space: pre-wrap;
}
.pt {
  text-align: right;
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--gold);
  margin-bottom: 6px;
}
hr {
  border: none;
  border-top: 1px solid var(--border);
  margin: 18px 0 14px;
}
.controls {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 16px;
}
.control {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
  color: var(--text-dim);
}
.control select {
  width: 60%;
  font-size: 12px;
  padding: 6px 8px;
}
.control.inline {
  justify-content: flex-start;
  gap: 8px;
}
.control.inline input[type="checkbox"] {
  width: auto;
}
.wanted {
  display: flex;
  gap: 10px;
  background: rgba(251, 146, 60, 0.08);
  border: 1px solid rgba(251, 146, 60, 0.3);
  border-radius: 4px;
  padding: 10px 12px;
  margin-bottom: 14px;
}
.warn {
  font-size: 18px;
  color: var(--cond-hp);
}
.wanted-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-dim);
  margin-bottom: 4px;
}
.wanted ul {
  margin: 0;
  padding-left: 16px;
  font-size: 12px;
  color: var(--text);
}
.legalities h3 {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  margin-bottom: 8px;
  font-family: var(--font-body);
  font-weight: 600;
}
.legality-grid {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px 10px;
  font-size: 11px;
}
.leg-format {
  color: var(--text-dim);
}
.leg-status {
  text-transform: capitalize;
  font-weight: 600;
  text-align: right;
}
.leg-legal       { color: var(--cond-nm); }
.leg-not-legal   { color: var(--text-faint); }
.leg-restricted  { color: var(--cond-mp); }
.leg-banned      { color: var(--cond-dmg); }
</style>
