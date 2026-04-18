<script setup>
import { computed, ref, h } from 'vue'
import { useCollectionStore } from '../stores/collection'
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
      const tokens = p.text.split(/({[^}]+})/g).filter(Boolean)
      return h(
        'div',
        { class: 'oracle' },
        tokens.flatMap((tok, i) => {
          if (/^{[^}]+}$/.test(tok)) return [h(ManaSymbol, { key: i, symbol: tok })]
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

const ptOrLoyalty = computed(() => {
  if (!card.value) return null
  if (card.value.loyalty != null && card.value.loyalty !== '') {
    return { kind: 'L', value: card.value.loyalty }
  }
  if (card.value.power != null && card.value.power !== '' && card.value.toughness != null) {
    return { kind: 'PT', value: `${card.value.power}/${card.value.toughness}` }
  }
  return null
})

async function patch(payload) {
  if (!entry.value) return
  await collection.updateEntry(entry.value.id, payload)
}

function onConditionChange(e) { patch({ condition: e.target.value }) }
function onLocationChange(e) {
  const val = e.target.value
  patch({ location_id: val === '' ? null : Number(val) })
}
function onFoilChange(e) { patch({ foil: e.target.checked }) }
function onQuantityChange(e) {
  const q = Math.max(1, Math.min(9999, Number(e.target.value) || 1))
  patch({ quantity: q })
}

function close() { collection.closeActiveEntry() }

const realLocations = computed(() => collection.locations)
</script>

<template>
  <aside v-if="entry" class="vk-detail">
    <header class="vk-detail-header">
      <button class="close" @click="close" title="Close" aria-label="Close">✕</button>
    </header>

    <div v-if="collection.detailLoading" class="loading">Loading…</div>

    <div v-else-if="card" class="vk-detail-body">
      <div class="vk-detail-art">
        <img
          :src="displayedImage || '/storage/card-back.jpg'"
          :alt="card.name"
          :class="{ flipping: showBack }"
        />
        <button v-if="isDfc" class="flip-btn" type="button" @click="flipped = !flipped" title="Flip card">
          ↺
        </button>
      </div>

      <h2 class="vk-detail-title">{{ card.name }}</h2>

      <div class="vk-detail-meta-row">
        <span class="set-badge">
          {{ (card.set_code || '').toUpperCase() }} · {{ card.collector_number }}
        </span>
        <span v-if="card.rarity" class="rarity" :data-r="card.rarity">{{ card.rarity }}</span>
        <ManaCost v-if="displayedManaCost" class="mana" :cost="displayedManaCost" />
      </div>

      <div v-if="displayedTypeLine" class="vk-detail-type">{{ displayedTypeLine }}</div>

      <div class="vk-detail-sep" />

      <div class="vk-detail-rules">
        <component :is="OracleText" :text="displayedOracle" />
      </div>

      <div v-if="ptOrLoyalty" class="vk-detail-pt">
        <div class="pt">
          <span v-if="ptOrLoyalty.kind === 'L'">Loyalty: {{ ptOrLoyalty.value }}</span>
          <span v-else>{{ ptOrLoyalty.value }}</span>
        </div>
      </div>

      <section class="vk-detail-section">
        <h4>Your Copies</h4>

        <div class="vk-field-row">
          <label class="vk-field">
            <span class="vk-field-label">Condition</span>
            <select :value="entry.condition" @change="onConditionChange" class="vk-field-input">
              <option>NM</option>
              <option>LP</option>
              <option>MP</option>
              <option>HP</option>
              <option>DMG</option>
            </select>
          </label>
          <label class="vk-field">
            <span class="vk-field-label">Location</span>
            <select :value="entry.location_id ?? ''" @change="onLocationChange" class="vk-field-input">
              <option value="">Unassigned</option>
              <option v-for="loc in realLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
            </select>
          </label>
        </div>

        <div class="vk-field-row">
          <label class="vk-field">
            <span class="vk-field-label">Quantity</span>
            <input
              type="number"
              min="1"
              max="9999"
              :value="entry.quantity"
              class="vk-field-input"
              @change="onQuantityChange"
            />
          </label>
          <label class="vk-field foil-field">
            <span class="vk-field-label">Foil</span>
            <span class="foil-toggle">
              <input type="checkbox" :checked="entry.foil" @change="onFoilChange" />
              <span>{{ entry.foil ? 'Yes' : 'No' }}</span>
            </span>
          </label>
        </div>
      </section>

      <section v-if="entry.wanted_by_decks?.length" class="wanted">
        <span class="warn">⚠</span>
        <div>
          <div class="wanted-title">Wanted by decks</div>
          <ul>
            <li v-for="(name, i) in entry.wanted_by_decks" :key="i">{{ name }}</li>
          </ul>
        </div>
      </section>

      <section v-if="card.legalities" class="vk-detail-section">
        <h4>Legalities</h4>
        <div class="legality-grid">
          <template v-for="[key, label] in FORMATS" :key="key">
            <div class="leg-format">{{ label }}</div>
            <div
              class="leg-status"
              :class="`leg-${(card.legalities[key] || 'unknown').replace('_', '-')}`"
            >
              {{ (card.legalities[key] || '—').replace('_', ' ') }}
            </div>
          </template>
        </div>
      </section>
    </div>
  </aside>
</template>

<style scoped>
.vk-detail {
  width: var(--detail-width);
  flex-shrink: 0;
  border-left: 1px solid var(--vk-line);
  background: var(--vk-bg-1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}

.vk-detail-header {
  padding: 10px 12px 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-shrink: 0;
}
.close {
  background: transparent;
  border: 0;
  color: var(--vk-ink-3);
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  padding: 0;
  transition: background 0.1s ease, color 0.1s ease;
}
.close:hover { background: var(--vk-bg-2); color: var(--vk-ink-1); }

.loading {
  color: var(--vk-ink-3);
  text-align: center;
  padding: 60px 0;
  font-style: italic;
}

.vk-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.vk-detail-art {
  position: relative;
  aspect-ratio: 63 / 88;
  border-radius: 10px;
  overflow: hidden;
  background: linear-gradient(135deg, #2a3544, #1a1a22);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}
.vk-detail-art img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 400ms ease;
}
.vk-detail-art img.flipping {
  transform: scaleX(-1) rotate(180deg);
}
.flip-btn {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.7);
  border: 1px solid var(--vk-gold-dim);
  color: var(--vk-gold);
  font-size: 16px;
  cursor: pointer;
  padding: 0;
}
.flip-btn:hover { background: var(--vk-gold); color: #1a1408; }

.vk-detail-title {
  margin-top: 16px;
  font-family: var(--font-display), serif;
  font-size: 22px;
  font-weight: 500;
  color: var(--vk-gold);
  letter-spacing: -0.01em;
  line-height: 1.15;
}

.vk-detail-meta-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  font-size: 11px;
  color: var(--vk-ink-2);
  flex-wrap: wrap;
}
.vk-detail-meta-row .set-badge {
  font-family: var(--font-mono), monospace;
  font-size: 10px;
  padding: 2px 6px;
  border: 1px solid var(--vk-line);
  border-radius: 3px;
  color: var(--vk-ink-2);
  letter-spacing: 0.04em;
}
.vk-detail-meta-row .rarity {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
  font-size: 10px;
}
.vk-detail-meta-row .rarity[data-r="mythic"]   { color: var(--r-mythic); }
.vk-detail-meta-row .rarity[data-r="rare"]     { color: var(--r-rare); }
.vk-detail-meta-row .rarity[data-r="uncommon"] { color: var(--r-uncommon); }
.vk-detail-meta-row .rarity[data-r="common"]   { color: var(--r-common); }
.vk-detail-meta-row .mana {
  margin-left: auto;
  font-size: 14px;
}

.vk-detail-type {
  margin-top: 10px;
  font-family: var(--font-display), serif;
  font-style: italic;
  font-size: 14px;
  color: var(--vk-ink-2);
}

.vk-detail-sep {
  height: 1px;
  background: var(--vk-line);
  margin: 14px 0;
  position: relative;
}
.vk-detail-sep::after {
  content: '';
  position: absolute;
  left: 50%;
  top: -3px;
  width: 7px;
  height: 7px;
  background: var(--vk-bg-1);
  border: 1px solid var(--vk-gold-dim);
  transform: translateX(-50%) rotate(45deg);
}

.vk-detail-rules {
  font-size: 13px;
  line-height: 1.5;
  color: var(--vk-ink-1);
}
/* .oracle is rendered by the OracleText functional component (h('div', { class: 'oracle' })) */
/*noinspection CssUnusedSymbol*/
:deep(.oracle) {
  white-space: pre-wrap;
}

.vk-detail-pt {
  margin-top: 12px;
  display: flex;
  justify-content: flex-end;
}
.vk-detail-pt .pt {
  font-family: var(--font-display), serif;
  font-size: 18px;
  font-weight: 500;
  color: var(--vk-ink-1);
  padding: 2px 14px;
  background: var(--vk-bg-2);
  border: 1px solid var(--vk-line);
  border-radius: 3px;
}

.vk-detail-section {
  margin-top: 20px;
}
.vk-detail-section h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vk-ink-3);
  margin: 0 0 10px;
  font-family: var(--font-sans), sans-serif;
}

.vk-field {
  display: block;
  margin-bottom: 0;
  flex: 1;
}
.vk-field-label {
  display: block;
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--vk-ink-3);
  margin-bottom: 4px;
  font-weight: 600;
}
.vk-field-input {
  width: 100%;
  height: 32px;
  padding: 0 10px;
  background: var(--vk-bg-0);
  border: 1px solid var(--vk-line);
  border-radius: var(--radius-sm);
  color: var(--vk-ink-1);
  font-size: 13px;
  font-family: inherit;
  outline: 0;
  appearance: none;
  -webkit-appearance: none;
}
.vk-field-input:focus { border-color: var(--vk-gold-dim); }
select.vk-field-input {
  background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236d6d78' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  padding-right: 26px;
}
.vk-field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 10px;
}
.foil-field .foil-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
  padding: 0 10px;
  background: var(--vk-bg-0);
  border: 1px solid var(--vk-line);
  border-radius: var(--radius-sm);
  font-size: 13px;
  color: var(--vk-ink-1);
  cursor: pointer;
}
.foil-field input[type="checkbox"] {
  width: auto;
  margin: 0;
  accent-color: var(--vk-gold);
}

.wanted {
  margin-top: 16px;
  display: flex;
  gap: 10px;
  background: rgba(251, 146, 60, 0.08);
  border: 1px solid rgba(251, 146, 60, 0.3);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}
.warn { font-size: 18px; color: var(--cond-hp); }
.wanted-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--vk-ink-3);
  margin-bottom: 4px;
}
.wanted ul {
  margin: 0;
  padding-left: 16px;
  font-size: 12px;
  color: var(--vk-ink-1);
}

.legality-grid {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px 10px;
  font-size: 11px;
}
.leg-format { color: var(--vk-ink-2); }
.leg-status {
  text-transform: capitalize;
  font-weight: 600;
  text-align: right;
}
/* .leg-* class names are built dynamically from card.legalities[format] in the template */
/*noinspection CssUnusedSymbol*/
.leg-legal       { color: var(--cond-nm); }
/*noinspection CssUnusedSymbol*/
.leg-not-legal   { color: var(--vk-ink-3); }
/*noinspection CssUnusedSymbol*/
.leg-restricted  { color: var(--cond-mp); }
/*noinspection CssUnusedSymbol*/
.leg-banned      { color: var(--cond-dmg); }
</style>
