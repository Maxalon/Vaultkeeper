<script setup>
import { computed } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'

const deck = useDeckStore()
const tabs = useTabsStore()

const LABELS = {
  banned_card: 'Banned card',
  color_identity_violation: 'Color identity violation',
  duplicate_card: 'Singleton violation',
  invalid_partner: 'Invalid partner pairing',
  invalid_commander: 'Invalid commander',
  invalid_companion: 'Invalid companion',
  deck_size: 'Deck size',
  too_many_cards: 'Too many copies',
  not_legal_in_format: 'Not legal in format',
  orphan_signature_spell: 'Orphan signature spell',
  missing_signature_spell: 'Missing signature spell',
}

const rows = computed(() => {
  const list = [...(deck.illegalities || [])]
  list.sort((a, b) => {
    if (a.ignored !== b.ignored) return a.ignored ? 1 : -1
    const t = (a.type || '').localeCompare(b.type || '')
    if (t !== 0) return t
    return (a.card_name || '').localeCompare(b.card_name || '')
  })
  return list
})

const counts = computed(() => {
  const active  = deck.illegalities.filter((i) => !i.ignored).length
  const ignored = deck.illegalities.filter((i) => i.ignored).length
  return { active, ignored }
})

async function toggle(row) {
  const payload = {
    illegality_type: row.type,
    scryfall_id_1: row.scryfall_id_1 ?? null,
    scryfall_id_2: row.scryfall_id_2 ?? null,
    oracle_id:     row.oracle_id     ?? null,
    expected_count: row.ignored ? null : row.expected_count,
  }
  if (row.ignored) {
    await deck.unignoreIllegality(deck.deck.id, payload)
  } else {
    await deck.ignoreIllegality(deck.deck.id, payload)
  }
}

function jumpToCard(row) {
  if (!row.scryfall_id_1) return
  const entry = deck.entries.find((e) => e.scryfall_id === row.scryfall_id_1)
  tabs.openTab('deck')
  if (entry) deck.setActiveEntry(entry.id)
}
</script>

<template>
  <div class="illegalities-tab">
    <header class="banner" :class="{ ok: !counts.active }">
      <template v-if="!counts.active">
        <span class="check">✓</span>
        <span>Deck is legal</span>
      </template>
      <template v-else>
        <span>{{ counts.active }} active illegalities · {{ counts.ignored }} ignored</span>
      </template>
    </header>

    <ul class="illegality-list">
      <li
        v-for="(row, i) in rows"
        :key="i"
        class="illegality-row"
        :class="{ ignored: row.ignored }"
      >
        <input type="checkbox" :checked="row.ignored" @change="toggle(row)" />
        <div class="row-body">
          <div class="row-head">
            <span class="row-type">{{ LABELS[row.type] || row.type }}</span>
            <button
              v-if="row.card_name && row.scryfall_id_1"
              type="button"
              class="row-card"
              @click="jumpToCard(row)"
            >{{ row.card_name }}</button>
          </div>
          <div class="row-desc">{{ row.message }}</div>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.illegalities-tab {
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.banner {
  padding: 0.75rem 1rem;
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
.banner.ok { color: #7cb98e; }
.check { font-weight: 700; font-size: 1.1rem; }
.illegality-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.illegality-row {
  display: flex;
  gap: 0.75rem;
  padding: 0.6rem 0.8rem;
  background: var(--vk-surface-raised, #26241f);
  border: 1px solid var(--vk-border, #33312c);
  border-radius: 5px;
  align-items: flex-start;
}
.illegality-row.ignored {
  opacity: 0.55;
  text-decoration: line-through;
  color: var(--vk-fg-dim, #a8a396);
}
.row-body { flex: 1; display: flex; flex-direction: column; gap: 0.2rem; }
.row-head { display: flex; align-items: center; gap: 0.75rem; font-size: 0.88rem; }
.row-type { font-weight: 600; }
.row-card {
  background: transparent;
  border: none;
  color: var(--vk-accent, #c99d3d);
  padding: 0;
  cursor: pointer;
  font-size: 0.85rem;
  text-decoration: underline dotted;
}
.row-desc { font-size: 0.8rem; color: var(--vk-fg-dim, #a8a396); }
</style>
