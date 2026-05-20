<script setup>
import { computed, ref } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useWantedMatchesStore } from '../../stores/wantedMatches'
import { avatarColor, avatarInitials } from '../../utils/avatarColor'
import { confirm as confirmDialog } from '../../composables/useConfirm'
import { useToast } from '../../composables/useToast'
import CardPeek from '../CardPeek.vue'
import ConditionBadge from '../ConditionBadge.vue'
import AssembleDeckModal from '../AssembleDeckModal.vue'
import AddCopiesModal from './AddCopiesModal.vue'
import EditPhysicalCopyModal from './EditPhysicalCopyModal.vue'

/**
 * Physical Copies tab — single view for "what does this deck still need?"
 * and "let me clean up the condition / foil / notes / printing of every
 * copy actually in the deck." Sits alongside Analysis / Illegalities in
 * the deckbuilder tab system.
 */
const deck = useDeckStore()
const wm = useWantedMatchesStore()
const toast = useToast()

const isAssembled = computed(() =>
  deck.entries.some((e) => e.physical_copy_id != null),
)
const canAssemble = computed(() => deck.entries.length > 0)
const assembleOpen = ref(false)

function openAssemble() {
  assembleOpen.value = true
}

async function onUnassemble() {
  if (!deck.deck) return
  const ok = await confirmDialog({
    title: 'Unassemble this deck?',
    message: 'Every physical copy in this deck will move to Review so you can decide where each one goes.',
    confirmText: 'Unassemble',
    destructive: true,
  })
  if (!ok) return
  try {
    const result = await deck.unassembleDeck(deck.deck.id)
    const flagged = result?.marked_for_review ?? 0
    let msg = 'Deck unassembled.'
    if (flagged > 0) msg += ` ${flagged} cop${flagged === 1 ? 'y' : 'ies'} marked for review.`
    toast.success(msg)
  } catch { /* deck.unassembleDeck already toasts on failure */ }
}

// Friend-availability lookup for missing rows. Returns the array of
// friends who own a copy of `scryfall_id`, or [] when there is no entry
// in the wanted-matches response (card not wanted, or zero matches).
function friendsForRow(row) {
  const m = wm.matchFor(row.scryfall_id)
  return m?.friends ?? []
}

function openMatchPanel(row) {
  wm.openPanel(row)
}

const ZONES = [
  { key: 'main',  label: 'Main deck' },
  { key: 'side',  label: 'Sideboard' },
  { key: 'maybe', label: 'Maybeboard' },
]

// Stable sort by card name within each section.
const sortedEntries = computed(() => {
  const list = [...(deck.entries || [])]
  list.sort((a, b) =>
    (a.scryfall_card?.name || '').localeCompare(b.scryfall_card?.name || '')
  )
  return list
})

const groups = computed(() => {
  const out = {}
  for (const z of ZONES) {
    const inZone = sortedEntries.value.filter((e) => e.zone === z.key)
    const missing = inZone.filter((e) => e.physical_copy_id == null)
    const bound = inZone.filter((e) => e.physical_copy_id != null)
    out[z.key] = { missing, bound, total: missing.length + bound.length }
  }
  return out
})

const counts = computed(() => {
  let bound = 0
  let missing = 0
  for (const e of deck.entries || []) {
    const q = Number(e.quantity) || 0
    if (e.physical_copy_id != null) bound += q
    else missing += q
  }
  return { bound, missing, total: bound + missing }
})

// Collapsed-state map. Keys: zone (`main`), and `${zone}:${kind}` for the
// bound/missing subsections. Default is expanded (absent = expanded).
const collapsed = ref({})
function toggle(key) {
  collapsed.value = { ...collapsed.value, [key]: !collapsed.value[key] }
}
function isCollapsed(key) {
  return !!collapsed.value[key]
}

const addTarget = ref(null)   // { entry } when AddCopiesModal is open
const editTarget = ref(null)  // { entry } when EditPhysicalCopyModal is open

function openAdd(entry) {
  addTarget.value = { entry }
}
function openEdit(entry) {
  editTarget.value = { entry }
}

// Open the detail sidebar for this entry. We deliberately do NOT switch
// to the deck tab — DeckDetailSidebar is rendered alongside TabSystem in
// DeckView, so it shows up on top of any tab the user is currently on.
function jumpToCard(entry) {
  deck.setActiveEntry(entry.id)
}

function describePhysical(p) {
  if (!p) return ''
  const bits = [p.condition]
  if (p.foil) bits.push('foil')
  if (p.location_name) bits.push(p.location_name)
  return bits.join(' · ')
}

// AddCopiesModal expects { deck, bound, wanted, card, scryfallId, zone, category }.
// For a missing row, bound is null (the row IS the wanted-only entry).
const addModalProps = computed(() => {
  if (!addTarget.value) return null
  const entry = addTarget.value.entry
  return {
    deck: { id: deck.deck.id, name: deck.deck.name },
    bound: null,
    wanted: entry,
    card: entry.scryfall_card,
    scryfallId: entry.scryfall_id,
    zone: entry.zone,
    category: entry.category,
  }
})

// ── Peek popover (matches CardListPanel's peek behaviour) ──────────────
const peek = ref({ entry: null, x: 0, y: 0, visible: false })

function readCardWidth() {
  const v = getComputedStyle(document.documentElement)
    .getPropertyValue('--card-width')
    .trim()
  const n = parseFloat(v)
  return Number.isFinite(n) && n > 0 ? n : 146
}

function onRowEnter(row, event) {
  const card = row.scryfall_card
  if (!card) return
  const isDfc = !!(card.is_dfc && card.image_normal_back)
  const cardW = readCardWidth()
  const peekW = isDfc ? cardW * 2 + 8 : cardW
  const peekH = Math.round((cardW * 88) / 63)
  const gap = 10
  const rect = event.currentTarget.getBoundingClientRect()

  let x = rect.right + gap
  if (x + peekW > window.innerWidth - 12) {
    x = rect.left - peekW - gap
  }
  let y = rect.top + rect.height / 2 - peekH / 2
  y = Math.max(12, Math.min(y, window.innerHeight - peekH - 12))

  // CardPeek reads `entry.card`; the deck row stores the printing on
  // `scryfall_card`, so wrap it. Image fields (image_normal,
  // image_normal_back, is_dfc) on scryfall_card already match the
  // selected printing for this entry.
  peek.value = { entry: { card }, x, y, visible: true }
}

function onRowLeave() {
  peek.value = { ...peek.value, visible: false }
}
</script>

<template>
  <div class="physical-tab">
    <header class="banner" :class="{ ok: !counts.missing }">
      <template v-if="!counts.missing">
        <span class="check">✓</span>
        <span>{{ counts.bound }} of {{ counts.total }} copies have a physical card.</span>
      </template>
      <template v-else>
        <span>
          {{ counts.bound }} of {{ counts.total }} copies have a physical card ·
          {{ counts.missing }} still need sourcing.
        </span>
      </template>
    </header>

    <div v-if="canAssemble" class="assemble-row">
      <button
        v-if="!isAssembled"
        type="button"
        class="btn assemble-btn"
        @click="openAssemble"
      >Assemble deck</button>
      <template v-else>
        <button
          type="button"
          class="btn assemble-btn"
          @click="openAssemble"
        >Reassemble</button>
        <button
          type="button"
          class="btn unassemble-btn"
          @click="onUnassemble"
        >Unassemble deck</button>
      </template>
    </div>

    <!-- Wanted-matches loading: indeterminate barber-pole stripe + caption.
         Disappears once wm.loading flips false. -->
    <div v-if="wm.loading" class="match-loading" role="status" aria-live="polite">
      <div class="stripe" aria-hidden="true" />
      <span class="match-loading-text">Checking which friends own missing cards…</span>
    </div>

    <template v-for="z in ZONES" :key="z.key">
      <section
        v-if="groups[z.key].total"
        class="zone"
        :class="{ collapsed: isCollapsed(z.key) }"
      >
        <button
          type="button"
          class="zone-head"
          :aria-expanded="!isCollapsed(z.key)"
          @click="toggle(z.key)"
        >
          <span class="chev" :class="{ rot: !isCollapsed(z.key) }">▶</span>
          <h3 class="zone-title">{{ z.label }}</h3>
          <span class="zone-count">
            {{ groups[z.key].bound.length }} bound ·
            {{ groups[z.key].missing.length }} missing
          </span>
        </button>

        <div v-if="!isCollapsed(z.key)" class="zone-body">
          <section
            v-if="groups[z.key].missing.length"
            class="section"
            :class="{ collapsed: isCollapsed(`${z.key}:missing`) }"
          >
            <button
              type="button"
              class="section-head"
              :aria-expanded="!isCollapsed(`${z.key}:missing`)"
              @click="toggle(`${z.key}:missing`)"
            >
              <span class="chev sm" :class="{ rot: !isCollapsed(`${z.key}:missing`) }">▶</span>
              <span>Missing copies</span>
              <span class="section-count">{{ groups[z.key].missing.length }}</span>
            </button>
            <ul v-if="!isCollapsed(`${z.key}:missing`)" class="rows">
              <li
                v-for="row in groups[z.key].missing"
                :key="`m-${row.id}`"
                class="row missing"
                @mouseenter="onRowEnter(row, $event)"
                @mouseleave="onRowLeave"
              >
                <button
                  type="button"
                  class="row-card"
                  @click="jumpToCard(row)"
                >{{ row.scryfall_card?.name || '—' }}</button>
                <span class="row-qty">×{{ row.quantity }}</span>
                <!-- Friend availability: appears once wm.fetch resolves
                     and at least one friend has a copy. Click opens the
                     WantedMatchPanel with full per-copy detail. -->
                <button
                  v-if="!wm.loading && friendsForRow(row).length > 0"
                  type="button"
                  class="friend-meta"
                  :title="`Available from ${friendsForRow(row).map((f) => f.username).join(', ')} — click to see copies`"
                  @click.stop="openMatchPanel(row)"
                >
                  <span class="friend-meta-dot" aria-hidden="true">·</span>
                  <span class="friend-avatars" aria-hidden="true">
                    <span
                      v-for="(friend, i) in friendsForRow(row).slice(0, 3)"
                      :key="friend.user_id"
                      class="friend-avatar"
                      :style="{
                        background: avatarColor(friend.username),
                        zIndex: friendsForRow(row).length - i,
                        marginLeft: i === 0 ? '0' : '-5px',
                      }"
                    >{{ avatarInitials(friend.username) }}</span>
                  </span>
                  <span class="friend-label">
                    {{ friendsForRow(row)[0].username }}<template v-if="friendsForRow(row).length > 1"> +{{ friendsForRow(row).length - 1 }}</template>
                  </span>
                </button>
                <span class="spacer" />
                <button type="button" class="action" @click="openAdd(row)">Add copies…</button>
              </li>
            </ul>
          </section>

          <section
            v-if="groups[z.key].bound.length"
            class="section"
            :class="{ collapsed: isCollapsed(`${z.key}:bound`) }"
          >
            <button
              type="button"
              class="section-head"
              :aria-expanded="!isCollapsed(`${z.key}:bound`)"
              @click="toggle(`${z.key}:bound`)"
            >
              <span class="chev sm" :class="{ rot: !isCollapsed(`${z.key}:bound`) }">▶</span>
              <span>Bound copies</span>
              <span class="section-count">{{ groups[z.key].bound.length }}</span>
            </button>
            <ul v-if="!isCollapsed(`${z.key}:bound`)" class="rows">
              <li
                v-for="row in groups[z.key].bound"
                :key="`b-${row.id}`"
                class="row bound"
                @mouseenter="onRowEnter(row, $event)"
                @mouseleave="onRowLeave"
              >
                <button
                  type="button"
                  class="row-card"
                  @click="jumpToCard(row)"
                >{{ row.scryfall_card?.name || '—' }}</button>
                <span class="row-qty">×{{ row.quantity }}</span>
                <ConditionBadge
                  v-if="row.physical_copy?.condition"
                  :value="row.physical_copy.condition"
                />
                <span v-if="row.physical_copy?.foil" class="foil-mark">FOIL</span>
                <span class="row-meta" :title="row.physical_copy?.notes || ''">
                  {{ describePhysical(row.physical_copy) }}
                </span>
                <span
                  v-if="row.physical_copy?.review_reason === 'default_values_applied'"
                  class="default-flag"
                  title="Assemble applied default values — confirm or correct."
                >defaults</span>
                <span class="spacer" />
                <button type="button" class="action" @click="openEdit(row)">Edit…</button>
              </li>
            </ul>
          </section>
        </div>
      </section>
    </template>

    <p v-if="!counts.total" class="empty">
      Add cards to the deck first — there's nothing to manage yet.
    </p>

    <CardPeek
      :entry="peek.entry"
      :x="peek.x"
      :y="peek.y"
      :visible="peek.visible"
    />

    <AddCopiesModal
      v-if="addTarget && addModalProps"
      v-bind="addModalProps"
      @close="addTarget = null"
    />
    <EditPhysicalCopyModal
      v-if="editTarget"
      :entry="editTarget.entry"
      @close="editTarget = null"
    />
    <AssembleDeckModal
      v-if="assembleOpen && deck.deck"
      :deck="deck.deck"
      :entries="deck.entries"
      @close="assembleOpen = false"
      @assembled="assembleOpen = false"
    />
  </div>
</template>

<style scoped>
.physical-tab {
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.banner {
  padding: 0.75rem 1rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
.banner.ok { color: #7cb98e; }
.check { font-weight: 700; font-size: 1.1rem; }

.assemble-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.btn {
  padding: 6px 14px;
  border-radius: 5px;
  border: 1px solid var(--hairline, #33312c);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}
.assemble-btn {
  background: var(--amber, #c99d3d);
  border-color: var(--amber, #c99d3d);
  color: #1a1814;
  font-weight: 600;
}
.assemble-btn:hover { background: #d4a93f; }
.unassemble-btn {
  background: transparent;
  color: var(--ink-70, #a8a396);
}
.unassemble-btn:hover { background: var(--bg-1, #1d1c1a); color: #c94040; border-color: #c94040; }

/* ── Wanted-matches indeterminate loading ─────────────────────────── */
/* Barber-pole stripe: amber/transparent diagonal bands scrolling
   left→right at a steady cadence. Width is 200% so the loop is
   seamless when translated by -50%. Caption sits beneath. */
.match-loading {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: -0.4rem;  /* nestle under the banner */
}
.match-loading .stripe {
  height: 3px;
  border-radius: 2px;
  background-image: repeating-linear-gradient(
    -45deg,
    var(--amber, #c9a552) 0,
    var(--amber, #c9a552) 6px,
    color-mix(in srgb, var(--amber, #c9a552) 20%, transparent) 6px,
    color-mix(in srgb, var(--amber, #c9a552) 20%, transparent) 12px
  );
  background-size: 200% 100%;
  animation: barber-pole 1.1s linear infinite;
}
@keyframes barber-pole {
  from { background-position: 0 0; }
  to   { background-position: -34px 0; } /* 2× pattern unit (12+12 + slack) */
}
.match-loading-text {
  font-size: 0.78rem;
  color: var(--ink-50, #7a7568);
  font-style: italic;
}

/* ── Friend-availability meta (inline on missing rows) ──────────────── */
.friend-meta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: 1px solid transparent;
  color: var(--ink-70, #a8a396);
  font: inherit;
  font-size: 0.78rem;
  padding: 2px 8px 2px 4px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s, color 0.1s;
  white-space: nowrap;
  flex-shrink: 0;
}
.friend-meta:hover {
  background: var(--bg-1, #1d1c1a);
  border-color: var(--amber-lo, #6e5421);
  color: var(--ink-100, #f0e9d6);
}
.friend-meta-dot {
  color: var(--ink-30, #4f4d47);
  font-weight: 700;
  margin-right: 2px;
}
.friend-avatars {
  display: inline-flex;
  align-items: center;
}
.friend-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  font-size: 7px;
  font-weight: 700;
  color: #fff;
  box-shadow: 0 0 0 1.5px var(--bg-2, #26241f);
  user-select: none;
}
.friend-label {
  color: inherit;
}

.zone {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.zone-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.4rem 0.25rem;
  background: transparent;
  border: 0;
  border-bottom: 1px solid var(--hairline, #33312c);
  color: inherit;
  font: inherit;
  cursor: pointer;
  text-align: left;
}
.zone-head:hover .zone-title { color: var(--amber, #c99d3d); }
.zone-title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.zone-count {
  margin-left: auto;
  font-size: 0.75rem;
  color: var(--ink-70, #a8a396);
}
.zone-body {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding-left: 0.4rem;
}

.section {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.section-head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
  padding: 0.25rem 0.1rem;
  background: transparent;
  border: 0;
  color: var(--ink-70, #a8a396);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
}
.section-head:hover { color: var(--amber, #c99d3d); }
.section-count {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
}

.chev {
  display: inline-block;
  font-size: 0.7rem;
  color: var(--ink-70, #a8a396);
  transform: rotate(0deg);
  transition: transform 120ms ease;
}
.chev.sm { font-size: 0.6rem; }
.chev.rot { transform: rotate(90deg); }

.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.75rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 5px;
  font-size: 0.85rem;
}
.row.missing { border-left: 2px solid var(--amber, #c99d3d); }
.row-card {
  background: transparent;
  border: none;
  color: var(--amber, #c99d3d);
  cursor: pointer;
  padding: 0;
  font: inherit;
  text-decoration: underline dotted;
  text-align: left;
  flex: 0 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 30ch;
}
.row-qty {
  font-variant-numeric: tabular-nums;
  color: var(--ink-70, #a8a396);
  min-width: 28px;
}
.row-meta {
  font-size: 0.78rem;
  color: var(--ink-70, #a8a396);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 28ch;
}
.foil-mark {
  font-size: 0.7rem;
  color: var(--amber, #c99d3d);
  letter-spacing: 0.1em;
}
.default-flag {
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 1px 5px;
  border: 1px solid var(--amber-lo, #6e5421);
  color: var(--amber, #c99d3d);
  border-radius: 3px;
}
.spacer { flex: 1; }
.action {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: inherit;
  padding: 4px 10px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.78rem;
}
.action:hover { background: var(--bg-1, #1d1c1a); border-color: var(--amber, #c99d3d); color: var(--amber); }
.empty {
  font-size: 0.85rem;
  color: var(--ink-70, #a8a396);
  font-style: italic;
}
</style>
