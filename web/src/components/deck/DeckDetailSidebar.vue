<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useWantedMatchesStore } from '../../stores/wantedMatches'
import { useDeckEntryActions } from '../../composables/useDeckEntryActions'
import { avatarColor, avatarInitials } from '../../utils/avatarColor'
import CardDetailBody from '../CardDetailBody.vue'
import HelpHint from '../HelpHint.vue'
import PriceLine from '../PriceLine.vue'
import PrintingPickerModal from '../PrintingPickerModal.vue'
import ZoneSelector from './ZoneSelector.vue'
import CategoryInput from './CategoryInput.vue'
import QuantityStepper from './QuantityStepper.vue'
import PhysicalCopyDropdown from './PhysicalCopyDropdown.vue'
import AddCopiesModal from './AddCopiesModal.vue'

const deck = useDeckStore()
const wm = useWantedMatchesStore()

const entry = computed(() =>
  deck.entries.find((e) => e.id === deck.activeEntryId) || null,
)
const { actions } = useDeckEntryActions(entry)

const deckId = computed(() => deck.deck?.id)

const isIllegal = computed(() => {
  if (!entry.value) return false
  return !!deck.cardLevelIllegalitiesByScryfallId[entry.value.scryfall_id]
})

const isGc = computed(() =>
  deck.deck?.format === 'commander'
  && entry.value?.scryfall_card?.commander_game_changer,
)

const isRoleLocked = computed(
  () => !!(entry.value?.is_commander || entry.value?.is_signature_spell),
)

const isBound = computed(() => entry.value?.physical_copy_id != null)
const oracleId = computed(() => entry.value?.scryfall_card?.oracle_id || null)

function close() {
  deck.activeEntryId = null
}

function patch(fields) {
  if (!entry.value || !deckId.value) return
  return deck.updateEntry(deckId.value, entry.value.id, fields)
}

function onZoneChange(zone) {
  if (entry.value && !isRoleLocked.value) {
    deck.moveEntryZone(deckId.value, entry.value.id, zone)
  }
}

/**
 * Find the bound + wishlist (unbound) siblings for the active entry's
 * (scryfall_id, zone) pair. Any unbound row counts as wishlist — even
 * one with `wanted: null` — because catalog-drag creates a slot in
 * that shape and the observer only flips `wanted=zone` on a later
 * grow. Without this, the sidebar would render Wanted=0 for those
 * rows and Wanted+ would route to growWanted, spawning a duplicate.
 */
function siblings(e) {
  if (!e) return { bound: null, wanted: null, wantedQty: 0 }
  const peers = deck.entries.filter(
    (r) => r.scryfall_id === e.scryfall_id && r.zone === e.zone,
  )
  const bound = peers.find((r) => r.physical_copy_id != null) || null
  const unbound = peers.filter((r) => r.physical_copy_id == null)
  const wanted = unbound[0] || null
  const wantedQty = unbound.reduce((s, r) => s + (r.quantity || 0), 0)
  return { bound, wanted, wantedQty }
}

const sibs       = computed(() => siblings(entry.value))
const wantedQty  = computed(() => sibs.value.wantedQty)
const ownedQty   = computed(() => sibs.value.bound?.quantity ?? 0)

// Friends who own a copy of this entry's card. Resolves once
// wantedMatches.fetch completes; empty until then or when no friend has
// the card. Click opens the WantedMatchPanel with full per-copy detail.
const matchFriends = computed(() => {
  if (!entry.value) return []
  return wm.matchFor(entry.value.scryfall_id)?.friends ?? []
})

function openMatchPanel() {
  if (entry.value) wm.openPanel(entry.value)
}

function onWantedInc() {
  if (!entry.value || !deckId.value) return
  const w = sibs.value.wanted
  if (w) {
    // Bump the existing unbound row in place. The observer's grow
    // hook auto-sets `wanted=zone` if it wasn't already, so this
    // handles both "wanted=null catalog row" and "true wishlist row"
    // paths without spawning a duplicate.
    deck.updateEntry(deckId.value, w.id, { quantity: w.quantity + 1 })
  } else {
    // Pure-bound slot, no wishlist sibling yet — POST a fresh wanted
    // entry via /decks/{id}/wanted.
    deck.growWanted(deckId.value, entry.value.scryfall_id, entry.value.zone)
  }
}

function onWantedDec() {
  const w = sibs.value.wanted
  if (!w || !deckId.value) return
  if (w.quantity <= 1) {
    // Wanted-only rows have no bound CE, so deletion has no observer
    // side-effects (no review queue noise).
    deck.removeEntry(deckId.value, w.id)
  } else {
    deck.updateEntry(deckId.value, w.id, { quantity: w.quantity - 1 })
  }
}

function onOwnedDec() {
  const b = sibs.value.bound
  if (!b || !deckId.value) return
  if (b.quantity <= 1) {
    // removeEntry's default path queues the freed CE for review with
    // reason `no_location` (DeckEntryObserver). The store also pops a
    // toast with a "Sold / discarded" override, and the user can open
    // /review to finalize the routing.
    deck.removeEntry(deckId.value, b.id)
  } else {
    // Shrinking a bound slot triggers the same review-queue path via
    // the observer's relocateIfInDeckLocation hook.
    deck.updateEntry(deckId.value, b.id, { quantity: b.quantity - 1 })
  }
}

const showAddCopies = ref(false)
function onOwnedInc() {
  if (!entry.value) return
  showAddCopies.value = true
}

function runAction(action) {
  action.run().catch(() => { /* store-level toast */ })
}

const printingPickerOpen = ref(false)
function openPrintingPicker() {
  if (!oracleId.value || isBound.value) return
  printingPickerOpen.value = true
}

const FINISH_OPTIONS = ['nonfoil', 'foil', 'etched']
const FINISH_LABELS  = { nonfoil: 'Nonfoil', foil: 'Foil', etched: 'Etched' }
const FINISH_HINTS   = {
  nonfoil: 'This printing has no non-foil version.',
  foil:    'This printing has no foil version.',
  etched:  'This printing has no etched version.',
}

const printingFinishes = computed(() => {
  // Pre-sync data: when the printing's `finishes` array is null/missing
  // (i.e. the row predates the finishes column being populated), fall
  // back to {nonfoil, foil} rather than allowing all options. Etched is
  // post-2021 and rare — defaulting it OFF is the safer 95% answer until
  // the next bulk-sync repopulates the column. Glossy is treated as
  // nonfoil-equivalent (not user-selectable as its own option).
  const raw = entry.value?.scryfall_card?.finishes
  if (!Array.isArray(raw)) return new Set(['nonfoil', 'foil'])
  const set = new Set(raw)
  if (set.has('glossy')) set.add('nonfoil')
  return set
})

function finishSupported(opt) {
  return printingFinishes.value.has(opt)
}

const currentFinish = computed(() => {
  // Bound entries source finish from the bound CE; unbound from the slot
  // itself. Defaults to 'nonfoil' when both flags are null/undefined.
  const src = isBound.value ? (entry.value?.physical_copy || {}) : (entry.value || {})
  if (src.is_etched) return 'etched'
  if (src.foil)      return 'foil'
  return 'nonfoil'
})

function setFinish(next) {
  if (!entry.value || !deckId.value || isBound.value) return
  if (next === currentFinish.value) return
  if (!finishSupported(next)) return
  patch({ foil: next === 'foil', is_etched: next === 'etched' })
}

function onPickPrinting(scryfallId, finishes) {
  if (!entry.value || !deckId.value || scryfallId === entry.value.scryfall_id) return
  const fields = { scryfall_id: scryfallId }
  // Auto-snap finish when the new printing doesn't support the current
  // selection. Silently picks the first finish the new printing supports
  // so the slot never lands in an impossible (printing × finish) state.
  if (Array.isArray(finishes) && !finishes.includes(currentFinish.value)) {
    const snap = finishes[0] ?? 'nonfoil'
    fields.foil      = snap === 'foil'
    fields.is_etched = snap === 'etched'
  }
  patch(fields)
}
</script>

<template>
  <aside v-if="entry" class="vk-detail vk-detail--deck">
    <header class="vk-detail-header">
      <button
        v-if="!isBound && oracleId"
        type="button"
        class="choose-printing-btn"
        @click="openPrintingPicker"
      >Choose printing</button>
      <button class="close" type="button" @click="close" title="Close" aria-label="Close">✕</button>
    </header>

    <div class="vk-detail-body">
      <div class="card-wrap" :class="{ 'illegal-glow': isIllegal }">
        <CardDetailBody :card="entry.scryfall_card" :show-legalities="false" />
        <span v-if="isGc" class="gc-badge">GC</span>
      </div>

      <PriceLine
        :prices="entry.scryfall_card?.prices"
        :foil="currentFinish === 'foil'"
        :is-etched="currentFinish === 'etched'"
      />

      <section class="vk-detail-section">
        <div class="qty-row">
          <div class="qty-cell">
            <h4 class="qty-label">
              <span>Wanted</span>
              <HelpHint
                text="Cards on your wishlist for this deck. + adds one to your wishlist; − removes one. When you actually buy a wanted copy, use the Owned + button to convert it into an owned copy."
              />
            </h4>
            <QuantityStepper
              :value="wantedQty"
              :min="0"
              :dec-disabled="wantedQty === 0"
              @inc="onWantedInc"
              @dec="onWantedDec"
            />
          </div>
          <div class="qty-cell">
            <h4 class="qty-label">
              <span>Owned</span>
              <HelpHint
                text="Physical copies of this card sitting in this deck. − removes one and routes the freed copy to the Review screen so you can decide where it goes (binder, sold, etc.). + opens a dialog where you can add new copies and pick a source for each."
              />
            </h4>
            <QuantityStepper
              :value="ownedQty"
              :min="0"
              :dec-disabled="ownedQty === 0"
              @inc="onOwnedInc"
              @dec="onOwnedDec"
            />
          </div>
        </div>

        <!-- Friend availability for the wanted side. Renders only when
             this card is on the wishlist AND the matches fetch has
             surfaced at least one friend with an available copy. Click
             opens WantedMatchPanel with conditions / locations / foil. -->
        <button
          v-if="wantedQty > 0 && matchFriends.length > 0"
          type="button"
          class="match-hint"
          @click="openMatchPanel"
        >
          <span class="match-hint-avatars" aria-hidden="true">
            <span
              v-for="(friend, i) in matchFriends.slice(0, 3)"
              :key="friend.user_id"
              class="match-hint-avatar"
              :style="{
                background: avatarColor(friend.username),
                zIndex: matchFriends.length - i,
                marginLeft: i === 0 ? '0' : '-5px',
              }"
            >{{ avatarInitials(friend.username) }}</span>
          </span>
          <span class="match-hint-text">
            {{ matchFriends.length }} friend{{ matchFriends.length === 1 ? '' : 's' }} own{{ matchFriends.length === 1 ? 's' : '' }} this
          </span>
          <span class="match-hint-caret" aria-hidden="true">›</span>
        </button>
      </section>

      <section class="vk-detail-section">
        <h4>Zone</h4>
        <ZoneSelector
          :value="entry.zone"
          :disabled="isRoleLocked"
          @change="onZoneChange"
        />
      </section>

      <section v-if="actions.length" class="vk-detail-section">
        <h4>{{ entry.is_commander ? 'Commander' : entry.is_signature_spell ? 'Signature spell' : 'Actions' }}</h4>
        <div class="role-actions">
          <button
            v-for="a in actions"
            :key="a.id"
            type="button"
            class="role-btn"
            :class="{
              'role-btn--primary': a.kind === 'primary',
              'role-btn--danger':  a.kind === 'danger',
            }"
            @click="runAction(a)"
          >
            <span>{{ a.label }}</span>
            <span v-if="a.hint" class="role-hint">{{ a.hint }}</span>
          </button>
        </div>
      </section>

      <section class="vk-detail-section">
        <h4>Category</h4>
        <CategoryInput
          :value="entry.category || ''"
          :suggestions="deck.categoriesInDeck"
          @commit="patch({ category: $event || null })"
        />
      </section>

      <section class="vk-detail-section">
        <h4>
          <span>Finish</span>
          <HelpHint
            v-if="isBound"
            text="Bound copies inherit finish from the physical copy. Edit it from the Physical Copies tab."
          />
        </h4>
        <div class="finish-row" role="radiogroup" aria-label="Finish">
          <button
            v-for="opt in FINISH_OPTIONS"
            :key="opt"
            type="button"
            role="radio"
            class="finish-btn"
            :class="{ selected: currentFinish === opt }"
            :aria-checked="currentFinish === opt"
            :disabled="isBound || !finishSupported(opt)"
            :title="!finishSupported(opt) ? FINISH_HINTS[opt] : null"
            @click="setFinish(opt)"
          >{{ FINISH_LABELS[opt] }}</button>
        </div>
      </section>

      <section v-if="!isBound" class="vk-detail-section">
        <h4>Physical copy</h4>
        <PhysicalCopyDropdown
          :scryfall-id="entry.scryfall_id"
          :value="entry.physical_copy_id"
          @select="patch({ physical_copy_id: $event })"
        />
      </section>
    </div>

    <PrintingPickerModal
      v-model:open="printingPickerOpen"
      :oracle-id="oracleId"
      :card-name="entry.scryfall_card?.name || ''"
      :selected-printing-id="entry.scryfall_id"
      @select="onPickPrinting"
    />

    <AddCopiesModal
      v-if="showAddCopies"
      :deck="deck.deck"
      :bound="sibs.bound"
      :wanted="sibs.wanted"
      :card="entry.scryfall_card"
      :scryfall-id="entry.scryfall_id"
      :zone="entry.zone"
      :category="entry.category"
      @close="showAddCopies = false"
    />
  </aside>
</template>

<style scoped>
.vk-detail {
  width: var(--detail-width, 360px);
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--bg-1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}

.vk-detail-header {
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  flex-shrink: 0;
}
.close {
  background: transparent;
  border: 0;
  color: var(--ink-50);
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
.close:hover { background: var(--bg-2); color: var(--ink-100); }

.vk-detail-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.choose-printing-btn {
  flex: 1;
  min-width: 0;
  background: var(--bg-0);
  border: 1px solid var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
  font-family: var(--font-mono), monospace;
  font-size: 12px;
  padding: 8px 6px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.1s ease, color 0.1s ease;
}
.choose-printing-btn:hover {
  background: var(--amber-lo);
  color: #1a1408;
}

.card-wrap {
  position: relative;
}
.gc-badge {
  position: absolute;
  bottom: 10px;
  right: 10px;
  background: linear-gradient(135deg, #f0c35c, #c99d3d);
  color: #0f0e0b;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
}

.vk-detail-section { margin-top: 20px; }
.vk-detail-section h4 {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 10px;
  font-family: var(--font-sans), sans-serif;
}

.qty-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.qty-cell .qty-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.match-hint {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-top: 10px;
  padding: 7px 10px;
  background: var(--bg-0);
  border: 1px solid var(--amber-lo, #6e5421);
  border-radius: var(--radius-sm, 4px);
  color: var(--ink-100);
  font: inherit;
  font-size: 12px;
  text-align: left;
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s;
}
.match-hint:hover {
  background: color-mix(in srgb, var(--amber, #c9a552) 8%, var(--bg-0));
  border-color: var(--amber, #c9a552);
}
.match-hint-avatars {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}
.match-hint-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  font-size: 7.5px;
  font-weight: 700;
  color: #fff;
  box-shadow: 0 0 0 1.5px var(--bg-0, #1a1610);
  user-select: none;
}
.match-hint-text {
  flex: 1;
  min-width: 0;
}
.match-hint-caret {
  color: var(--ink-50);
  font-size: 16px;
  line-height: 1;
  flex-shrink: 0;
}

.role-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.role-btn {
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  color: var(--ink-100);
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 13px;
  text-align: center;
  font: inherit;
  transition: background 0.1s ease, border-color 0.1s ease, color 0.1s ease;
}
.role-btn:hover { background: var(--bg-2); border-color: var(--amber-lo); }
.role-btn--primary {
  border-color: var(--amber-lo, #8a7436);
  color: var(--amber, #c9a552);
}
.role-btn--primary:hover {
  background: var(--amber-lo);
  color: #1a1408;
}
.role-btn--danger {
  color: var(--cond-hp, #d97757);
}
.role-btn--danger:hover {
  background: rgba(217, 119, 87, 0.08);
  border-color: var(--cond-hp, #d97757);
}
.role-btn .role-hint {
  display: block;
  font-size: 11px;
  color: var(--ink-50);
  margin-top: 2px;
  font-weight: 400;
}

.finish-row {
  display: flex;
  gap: 6px;
}
.finish-btn {
  flex: 1;
  background: var(--bg-0);
  border: 1px solid var(--hairline);
  color: var(--ink-100);
  padding: 6px 8px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font: inherit;
  font-size: 12px;
  transition: background 0.1s ease, border-color 0.1s ease, color 0.1s ease;
}
.finish-btn:hover:not(:disabled) {
  background: var(--bg-2);
  border-color: var(--amber-lo);
}
.finish-btn.selected {
  background: var(--amber-lo);
  border-color: var(--amber);
  color: #1a1408;
}
.finish-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
