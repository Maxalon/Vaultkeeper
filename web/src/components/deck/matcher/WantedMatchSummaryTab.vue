<script setup>
/**
 * WantedMatchSummaryTab — aggregated, sortable list of all wanted-card
 * matches across the entire deck.
 *
 * Reads everything from the wantedMatches Pinia store (matches, loading,
 * friend-count, derived states). The store's lifecycle is owned by
 * DeckView, so this tab works in any pane regardless of whether the
 * deck tab is open in the same leaf.
 */

import { computed, ref } from 'vue'
import { avatarColor, avatarInitials } from '../../../utils/avatarColor'
import HelpHint from '../../HelpHint.vue'
import NoReservationNotice from './NoReservationNotice.vue'
import { useWantedMatchesStore } from '../../../stores/wantedMatches'
import { useDeckStore } from '../../../stores/deck'

const wm = useWantedMatchesStore()
const deck = useDeckStore()

function refresh() {
  const id = deck.deck?.id
  wm.reset()
  if (id) wm.fetch(id)
}

// ── Sort ─────────────────────────────────────────────────────────────
const SORT_OPTIONS = [
  { value: 'card',    label: 'Card name' },
  { value: 'friends', label: 'Most friends' },
  { value: 'copies',  label: 'Most copies' },
]
const sortBy = ref('card')

// ── Derived data ──────────────────────────────────────────────────────

/** Total distinct friends across all matched cards (union of user_ids). */
const totalDistinctFriends = computed(() => {
  const ids = new Set()
  for (const m of wm.matches) {
    for (const f of m.friends) ids.add(f.user_id)
  }
  return ids.size
})

/** Cards that have at least one friend with available copies. */
const matchedCards = computed(() =>
  wm.matches.filter((m) => m.friends.length > 0),
)

/** Cards that are wanted but have zero friend matches. */
const unmatchedCards = computed(() =>
  wm.matches.filter((m) => m.friends.length === 0),
)

function friendCount(match) {
  return match.friends.length
}

function copyCount(match) {
  return match.friends.reduce(
    (s, f) => s + (f.available_copies?.length ?? 0),
    0,
  )
}

/** Sorted list of matched cards for display. */
const sortedMatches = computed(() => {
  const list = [...matchedCards.value]
  if (sortBy.value === 'friends') {
    list.sort((a, b) => friendCount(b) - friendCount(a) || a.card_name.localeCompare(b.card_name))
  } else if (sortBy.value === 'copies') {
    list.sort((a, b) => copyCount(b) - copyCount(a) || a.card_name.localeCompare(b.card_name))
  } else {
    list.sort((a, b) => a.card_name.localeCompare(b.card_name))
  }
  return list
})

function onRowClick(match) {
  wm.openPanel(match)
}
</script>

<template>
  <div class="wmst">
    <!-- ── Loading ──────────────────────────────────────────────────── -->
    <div v-if="wm.loading" class="wmst-state">
      <span class="wmst-spinner" aria-label="Loading…" />
      <span class="wmst-state-text">Checking friend collections…</span>
    </div>

    <!-- ── Error ────────────────────────────────────────────────────── -->
    <div v-else-if="wm.error" class="wmst-state wmst-state--error">
      <span class="wmst-state-icon" aria-hidden="true">⚠</span>
      <p class="wmst-state-text wmst-state-text--lead">Could not load matches</p>
      <p class="wmst-state-text wmst-state-text--sub">{{ wm.error }}</p>
    </div>

    <!-- ── C4: No friends at all ────────────────────────────────────── -->
    <div v-else-if="wm.friendCount === 0" class="wmst-state wmst-state--no-friends">
      <span class="wmst-state-icon" aria-hidden="true">🤝</span>
      <p class="wmst-state-text wmst-state-text--lead">No friends yet</p>
      <p class="wmst-state-text wmst-state-text--sub">
        Add friends to unlock the matcher. Once connected, you'll see who has
        the cards you need and can coordinate trades directly.
      </p>
    </div>

    <!-- ── C4: Friends have collections private ──────────────────────── -->
    <div v-else-if="wm.noVisibleFriends" class="wmst-state wmst-state--no-visible">
      <span class="wmst-state-icon" aria-hidden="true">🔒</span>
      <p class="wmst-state-text wmst-state-text--lead">Collections are private</p>
      <p class="wmst-state-text wmst-state-text--sub">
        You have friends, but none have set their collection to visible. Ask
        them to update their privacy settings — matches will appear automatically.
      </p>
    </div>

    <!-- ── No wanted cards at all ───────────────────────────────────── -->
    <div v-else-if="wm.matches.length === 0" class="wmst-state">
      <span class="wmst-state-icon" aria-hidden="true">🃏</span>
      <p class="wmst-state-text wmst-state-text--lead">No wanted cards</p>
      <p class="wmst-state-text wmst-state-text--sub">
        Mark deck entries as <strong>wanted</strong> and friend matches will
        appear here automatically.
      </p>
    </div>

    <!-- ── Has data ─────────────────────────────────────────────────── -->
    <template v-else>
      <!-- C4: Visibility-revoked notice (non-intrusive banner above list) -->
      <div v-if="wm.visibilityRevoked" class="wmst-revoked-notice" role="status">
        <span aria-hidden="true">🔕</span>
        <span class="wmst-revoked-text">A friend changed their collection visibility — matches may be stale.</span>
        <button type="button" class="wmst-revoked-refresh" @click="refresh">Refresh</button>
      </div>

      <!-- Summary banner -->
      <header class="wmst-banner">
        <div class="wmst-banner-body">
          <span class="wmst-banner-stat">
            <strong>{{ matchedCards.length }}</strong>
            / {{ wm.matches.length }} wanted cards matched
          </span>
          <span class="wmst-banner-sep" aria-hidden="true">·</span>
          <span class="wmst-banner-stat">
            <strong>{{ totalDistinctFriends }}</strong>
            friend{{ totalDistinctFriends === 1 ? '' : 's' }} can help
          </span>
        </div>
      </header>

      <!-- C5: No-reservation notice — always shown when there are matches,
           so users understand copies are not held before they reach out. -->
      <NoReservationNotice v-if="matchedCards.length > 0" />

      <!-- Sort controls -->
      <div v-if="matchedCards.length > 1" class="wmst-controls">
        <span class="wmst-sort-label">Sort:</span>
        <div class="wmst-sort-pills" role="group" aria-label="Sort matches by">
          <button
            v-for="opt in SORT_OPTIONS"
            :key="opt.value"
            type="button"
            class="wmst-pill"
            :class="{ 'wmst-pill--active': sortBy === opt.value }"
            @click="sortBy = opt.value"
          >{{ opt.label }}</button>
        </div>
      </div>

      <!-- Matched cards table -->
      <div v-if="sortedMatches.length" class="wmst-section">
        <h3 class="wmst-section-head">
          Matches
          <span class="wmst-section-count">{{ sortedMatches.length }}</span>
        </h3>
        <ul class="wmst-rows">
          <li
            v-for="match in sortedMatches"
            :key="match.scryfall_card_id"
            class="wmst-row"
            role="button"
            tabindex="0"
            :aria-label="`${match.card_name} — ${friendCount(match)} friend${friendCount(match) === 1 ? '' : 's'}, ${copyCount(match)} ${copyCount(match) === 1 ? 'copy' : 'copies'}`"
            @click="onRowClick(match)"
            @keydown.enter.space.prevent="onRowClick(match)"
          >
            <span class="wmst-card-name">{{ match.card_name }}</span>
            <span class="wmst-qty" title="Wanted quantity">×{{ match.wanted_quantity }}</span>

            <!-- Avatar stack (max 3 shown, then +N) -->
            <span class="wmst-avatars" aria-hidden="true">
              <span
                v-for="(friend, i) in match.friends.slice(0, 3)"
                :key="friend.user_id"
                class="wmst-avatar"
                :style="{
                  background: avatarColor(friend.username),
                  zIndex: match.friends.length - i,
                  marginLeft: i === 0 ? '0' : '-5px',
                }"
                :title="friend.username"
              >{{ avatarInitials(friend.username) }}</span>
              <span
                v-if="match.friends.length > 3"
                class="wmst-avatar wmst-avatar--overflow"
                :style="{ marginLeft: '-5px' }"
              >+{{ match.friends.length - 3 }}</span>
            </span>

            <span class="wmst-meta">
              {{ friendCount(match) }} friend{{ friendCount(match) === 1 ? '' : 's' }}
              &middot;
              {{ copyCount(match) }} {{ copyCount(match) === 1 ? 'copy' : 'copies' }}
            </span>

            <span class="wmst-caret" aria-hidden="true">›</span>
          </li>
        </ul>
      </div>

      <!-- Unmatched cards (wanted but no friend has a copy) -->
      <div v-if="unmatchedCards.length" class="wmst-section wmst-section--unmatched">
        <h3 class="wmst-section-head wmst-section-head--muted">
          No matches yet
          <span class="wmst-section-count">{{ unmatchedCards.length }}</span>
        </h3>
        <ul class="wmst-rows wmst-rows--unmatched">
          <li
            v-for="card in unmatchedCards"
            :key="card.scryfall_card_id"
            class="wmst-row wmst-row--unmatched"
          >
            <span class="wmst-card-name">{{ card.card_name }}</span>
            <span class="wmst-qty">×{{ card.wanted_quantity }}</span>
            <span class="wmst-unmatched-hint">None of your friends have this available</span>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Shell ──────────────────────────────────────────────────────────── */
.wmst {
  height: 100%;
  overflow-y: auto;
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  font-family: var(--font-sans), sans-serif;
}

/* ── Revoked-visibility notice ──────────────────────────────────────── */
.wmst-revoked-notice {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: color-mix(in srgb, var(--amber, #c9a552) 8%, transparent);
  border: 1px solid color-mix(in srgb, var(--amber, #c9a552) 30%, transparent);
  border-radius: 5px;
  font-size: 12px;
  color: var(--ink-70);
  line-height: 1.4;
}
.wmst-revoked-text {
  flex: 1;
}
.wmst-revoked-refresh {
  flex-shrink: 0;
  background: transparent;
  border: 1px solid color-mix(in srgb, var(--amber, #c9a552) 50%, transparent);
  color: var(--amber, #c9a552);
  font-size: 11px;
  font-family: var(--font-sans), sans-serif;
  padding: 3px 9px;
  border-radius: 999px;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.1s, border-color 0.1s;
}
.wmst-revoked-refresh:hover {
  background: color-mix(in srgb, var(--amber, #c9a552) 12%, transparent);
  border-color: var(--amber, #c9a552);
}

/* ── State views (loading / error / empty) ──────────────────────────── */
.wmst-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 3rem 2rem;
  text-align: center;
}
.wmst-state--error .wmst-state-icon { color: var(--cond-hp, #d97757); }
.wmst-state--no-friends .wmst-state-text--lead { color: var(--amber, #c9a552); }
.wmst-state--no-visible .wmst-state-icon { color: var(--ink-30); }
.wmst-state-icon { font-size: 32px; line-height: 1; }
.wmst-state-text { font-size: 13px; color: var(--ink-70, #a8a396); line-height: 1.5; margin: 0; }
.wmst-state-text--lead { font-size: 15px; font-weight: 600; color: var(--ink-100); }
.wmst-state-text--sub { font-size: 12px; color: var(--ink-50); }
.wmst-state-text strong { color: var(--ink-100); font-weight: 600; }

.wmst-spinner {
  display: inline-block;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid var(--hairline, #33312c);
  border-top-color: var(--amber, #c9a552);
  animation: wmst-spin 0.8s linear infinite;
}
@keyframes wmst-spin { to { transform: rotate(360deg); } }

/* ── Banner ─────────────────────────────────────────────────────────── */
.wmst-banner {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0.65rem 1rem;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 6px;
}
.wmst-banner-body {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  flex-wrap: wrap;
  font-size: 0.85rem;
  color: var(--ink-70);
}
.wmst-banner-stat strong { color: var(--ink-100); }
.wmst-banner-sep { color: var(--ink-30); }

/* ── Sort controls ──────────────────────────────────────────────────── */
.wmst-controls {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.wmst-sort-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--ink-50);
}
.wmst-sort-pills {
  display: flex;
  gap: 4px;
}
.wmst-pill {
  background: transparent;
  border: 1px solid var(--hairline, #33312c);
  color: var(--ink-70);
  font-size: 11px;
  font-family: var(--font-sans), sans-serif;
  padding: 3px 9px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s, color 0.1s;
}
.wmst-pill:hover {
  background: var(--bg-2);
  border-color: var(--ink-30);
  color: var(--ink-100);
}
.wmst-pill--active {
  background: var(--amber-lo, #3a2f10);
  border-color: var(--amber, #c9a552);
  color: var(--amber, #c9a552);
}

/* ── Sections ───────────────────────────────────────────────────────── */
.wmst-section { display: flex; flex-direction: column; gap: 0.4rem; }
.wmst-section-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  padding-bottom: 4px;
  border-bottom: 1px solid var(--hairline, #33312c);
}
.wmst-section-head--muted { color: var(--ink-30); }
.wmst-section-count {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
  font-size: 11px;
}

/* ── Rows ────────────────────────────────────────────────────────────── */
.wmst-rows { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }

.wmst-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: var(--bg-2, #26241f);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 5px;
  cursor: pointer;
  transition: border-color 0.12s, background 0.12s;
  font-size: 13px;
}
.wmst-row:hover {
  background: var(--bg-1, #1d1c1a);
  border-color: var(--amber-lo, #6e5421);
}
.wmst-row:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--amber, #c9a552);
}
.wmst-row--unmatched {
  cursor: default;
  opacity: 0.65;
}
.wmst-row--unmatched:hover { background: var(--bg-2); border-color: var(--hairline); }

.wmst-card-name {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-weight: 500;
  color: var(--ink-100);
}
.wmst-qty {
  font-size: 11px;
  color: var(--ink-50);
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}

/* Avatar mini-stack */
.wmst-avatars {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}
.wmst-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  font-size: 7px;
  font-weight: 700;
  color: #fff;
  box-shadow: 0 0 0 1.5px var(--bg-0, #1a1610);
  user-select: none;
  flex-shrink: 0;
}
.wmst-avatar--overflow {
  background: var(--bg-2, #2e2b26);
  color: var(--ink-70, #a8a396);
  font-size: 6.5px;
}

.wmst-meta {
  font-size: 11px;
  color: var(--ink-50);
  white-space: nowrap;
  flex-shrink: 0;
}
.wmst-caret {
  font-size: 16px;
  color: var(--ink-30);
  flex-shrink: 0;
  line-height: 1;
}
.wmst-unmatched-hint {
  font-size: 11px;
  color: var(--ink-30);
  flex: 1;
  text-align: right;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
