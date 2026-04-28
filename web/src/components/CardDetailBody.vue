<script setup>
import { computed, ref } from 'vue'
import ManaCost from './ManaCost.vue'
import ManaSymbol from './ManaSymbol.vue'

/**
 * Pure card-display body. Shared between the collection DetailSidebar
 * (which wraps this with "Your Copies" / "Wanted by decks") and the
 * catalog's CatalogDetailSidebar (which wraps this with "Printings").
 *
 * `card` takes either a raw ScryfallCard shape OR the `.card` field of
 * a collection entry — both are the same flat object shape.
 */
const props = defineProps({
  card: { type: Object, required: true },
})

const flipped = ref(false)

const isDfc = computed(() => !!props.card?.is_dfc)
const showBack = computed(() => isDfc.value && flipped.value)

const displayedImage = computed(() => {
  const c = props.card
  if (!c) return null
  if (showBack.value) return c.image_large_back || c.image_normal_back
  return c.image_large || c.image_normal
})
const displayedManaCost = computed(() =>
  showBack.value ? props.card?.mana_cost_back : props.card?.mana_cost,
)
const displayedTypeLine = computed(() =>
  showBack.value ? props.card?.type_line_back : props.card?.type_line,
)
const displayedOracle = computed(() =>
  showBack.value ? props.card?.oracle_text_back : props.card?.oracle_text,
)

// Tokenize oracle text into a flat list the template can render: each
// entry is either a mana symbol, a span of text, or an explicit line break.
// Done as data + template (rather than an inline render function) so Vue's
// SFC compiler emits everything statically — no runtime `new Function` paths,
// which our CSP forbids (script-src without 'unsafe-eval').
const oracleTokens = computed(() => {
  const text = displayedOracle.value
  if (!text) return []
  const out = []
  let key = 0
  for (const tok of text.split(/({[^}]+})/g)) {
    if (!tok) continue
    if (/^{[^}]+}$/.test(tok)) {
      out.push({ key: key++, kind: 'mana', value: tok })
      continue
    }
    const lines = tok.split(/\n+/)
    lines.forEach((line, i) => {
      out.push({ key: key++, kind: 'text', value: line })
      if (i < lines.length - 1) out.push({ key: key++, kind: 'br' })
    })
  }
  return out
})

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
  const c = props.card
  if (!c) return null
  if (c.loyalty != null && c.loyalty !== '') {
    return { kind: 'L', value: c.loyalty }
  }
  if (c.power != null && c.power !== '' && c.toughness != null) {
    return { kind: 'PT', value: `${c.power}/${c.toughness}` }
  }
  return null
})
</script>

<template>
  <div class="card-detail-body">
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
      <div v-if="oracleTokens.length" class="oracle">
        <template v-for="tok in oracleTokens" :key="tok.key">
          <ManaSymbol v-if="tok.kind === 'mana'" :symbol="tok.value" />
          <span v-else-if="tok.kind === 'text'">{{ tok.value }}</span>
          <br v-else />
        </template>
      </div>
    </div>

    <div v-if="ptOrLoyalty" class="vk-detail-pt">
      <div class="pt">
        <span v-if="ptOrLoyalty.kind === 'L'">Loyalty: {{ ptOrLoyalty.value }}</span>
        <span v-else>{{ ptOrLoyalty.value }}</span>
      </div>
    </div>

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
</template>

<style scoped>
.card-detail-body { display: contents; }

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
.vk-detail-art img.flipping { transform: scaleX(-1) rotate(180deg); }
.flip-btn {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.7);
  border: 1px solid var(--amber-lo);
  color: var(--amber);
  font-size: 16px;
  cursor: pointer;
  padding: 0;
}
.flip-btn:hover { background: var(--amber); color: #1a1408; }

.vk-detail-title {
  margin-top: 16px;
  font-family: var(--font-display), serif;
  font-size: 20px;
  font-weight: 600;
  color: var(--ink-100);
  margin-bottom: 8px;
}

.vk-detail-meta-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 10px;
  font-size: 12px;
}
.set-badge { color: var(--ink-50); font-family: var(--font-mono), monospace; }
.rarity { text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; font-size: 10px; }
.rarity[data-r="mythic"] { color: #f09c40; }
.rarity[data-r="rare"]   { color: #c9a552; }
.rarity[data-r="uncommon"] { color: #b3c2d0; }
.rarity[data-r="common"]   { color: var(--ink-50); }

.vk-detail-type { color: var(--ink-70); font-size: 13px; margin-bottom: 10px; }
.vk-detail-sep { height: 1px; background: var(--hairline); margin: 10px 0 12px; }

.vk-detail-rules :deep(.oracle) { font-size: 13px; line-height: 1.45; color: var(--ink-70); }
.vk-detail-rules :deep(.oracle .mana-symbol) { display: inline-flex; vertical-align: middle; margin: 0 1px; }

.vk-detail-pt { margin-top: 10px; display: flex; justify-content: flex-end; }
.pt { background: var(--bg-2); border: 1px solid var(--hairline); padding: 2px 10px; border-radius: 4px; font-family: var(--font-mono), monospace; font-size: 12px; color: var(--ink-100); }

.vk-detail-section { margin-top: 18px; }
.vk-detail-section h4 {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin: 0 0 8px;
}

.legality-grid {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 10px;
  font-size: 12px;
}
.leg-format { color: var(--ink-50); }
.leg-status { text-transform: capitalize; }
.leg-legal { color: #7cb98e; }
.leg-banned { color: #d06a6a; }
.leg-restricted { color: #d0a050; }
.leg-not-legal { color: var(--ink-50); }
</style>
