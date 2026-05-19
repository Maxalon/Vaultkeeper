<script setup>
/**
 * WantedMatchPanel — slide-in side panel that lists, for a single wanted
 * deck entry, every friend who has an available copy, along with each
 * copy's condition, foil state, and storage location.
 *
 * "Available" means location.role = 'user' (not assembled into any deck).
 * This is enforced server-side; the panel just renders what it receives.
 *
 * No in-app messaging in v1. Coordination is out-of-band — the only action
 * exposed is "Copy username" so the viewer can contact the friend themselves.
 *
 * API contract (GET /decks/{deck}/wanted-matches) returns the shape:
 *   [{ scryfall_card_id, card_name, wanted_quantity,
 *      friends: [{ user_id, username, available_copies: [
 *        { collection_entry_id, condition, foil, location_name }
 *      ]}]
 *   }]
 *
 * Reads everything from the wantedMatches Pinia store (active match,
 * loading, error, friend-count + derived states). DeckView mounts a
 * single instance whenever the store has an activeMatch.
 *
 * Emits
 *  close   — user dismissed the panel
 */

import { computed, ref } from 'vue'
import ConditionBadge from '../../ConditionBadge.vue'
import HelpHint from '../../HelpHint.vue'
import NoReservationNotice from './NoReservationNotice.vue'
import { avatarColor, avatarInitials } from '../../../utils/avatarColor'
import { useWantedMatchesStore } from '../../../stores/wantedMatches'

const wm = useWantedMatchesStore()

const emit = defineEmits(['close'])

// ── Copy-to-clipboard state ───────────────────────────────────────────
const copiedUserId = ref(null)
let copiedTimer = null

async function copyUsername(friend) {
  try {
    await navigator.clipboard.writeText(friend.username)
    copiedUserId.value = friend.user_id
    if (copiedTimer) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copiedUserId.value = null }, 2000)
  } catch {
    // Clipboard not available (non-HTTPS or denied) — fall back silently
  }
}

// ── Computed helpers ──────────────────────────────────────────────────
const friends = computed(() => wm.activeMatch?.friends ?? [])
const cardName = computed(() => wm.activeMatch?.card_name ?? '')
const wantedQty = computed(() => wm.activeMatch?.wanted_quantity ?? 1)

const hasFriends = computed(() => friends.value.length > 0)

/** Total available copy count across all friends — used in the header. */
const totalCopies = computed(() =>
  friends.value.reduce((s, f) => s + (f.available_copies?.length ?? 0), 0),
)

// ── C4: Distinct empty-state discrimination ───────────────────────────
/** User has zero accepted friends. Show onboarding nudge. */
const isNoFriendsState = computed(() =>
  !wm.loading && !wm.error && !hasFriends.value && wm.friendCount === 0,
)

/**
 * User has friends, but none have made their collection visible.
 * Inferred via the store getter (friendCount > 0 and zero matches across
 * the entire deck — distinguishes "this card isn't owned" from "all
 * friends are private").
 */
const isNoVisibleFriendsState = computed(() =>
  !wm.loading && !wm.error && !hasFriends.value &&
  wm.friendCount !== null && wm.friendCount > 0 && wm.noVisibleFriends,
)

/**
 * Friend revoked collection visibility mid-session — the most disruptive
 * case. Show a prominent banner rather than the generic "no matches" state.
 */
const isVisibilityRevokedState = computed(() =>
  !wm.loading && !wm.error && wm.visibilityRevoked,
)

/** Generic "0 matches for this specific card" (friends exist + are visible). */
const isNoCardMatchState = computed(() =>
  !wm.loading && !wm.error && !hasFriends.value &&
  !isNoFriendsState.value && !isNoVisibleFriendsState.value && !isVisibilityRevokedState.value,
)
</script>

<template>
  <aside class="wmp" role="complementary" aria-label="Friend match details">
    <!-- ── Header ────────────────────────────────────────────────────── -->
    <header class="wmp-header">
      <div class="wmp-title-row">
        <span class="wmp-title" :title="cardName">{{ cardName || 'Wanted card' }}</span>
        <HelpHint
          text="No reservation system: if a friend has only one copy and multiple people want it, everyone sees it here. Reach out directly to coordinate — Vaultkeeper doesn't track who gets it."
          position="bottom"
          :width="260"
        />
        <button
          class="wmp-close"
          type="button"
          title="Close"
          aria-label="Close panel"
          @click="emit('close')"
        >✕</button>
      </div>
      <p v-if="!wm.loading && !wm.error && hasFriends" class="wmp-subtitle">
        {{ friends.length }} friend{{ friends.length === 1 ? '' : 's' }} ·
        {{ totalCopies }} copy{{ totalCopies === 1 ? '' : 'ies' }} available
      </p>
    </header>

    <!-- ── Loading ───────────────────────────────────────────────────── -->
    <div v-if="wm.loading" class="wmp-state">
      <span class="wmp-spinner" aria-label="Loading…" />
      <span class="wmp-state-text">Checking friend collections…</span>
    </div>

    <!-- ── Error ─────────────────────────────────────────────────────── -->
    <div v-else-if="wm.error" class="wmp-state wmp-state--error">
      <span class="wmp-state-icon" aria-hidden="true">⚠</span>
      <span class="wmp-state-text">{{ wm.error }}</span>
    </div>

    <!-- ── C4: No friends at all ────────────────────────────────────── -->
    <div v-else-if="isNoFriendsState" class="wmp-state wmp-state--no-friends">
      <span class="wmp-state-icon" aria-hidden="true">🤝</span>
      <p class="wmp-state-text wmp-state-text--lead">No friends yet</p>
      <p class="wmp-state-text wmp-state-text--sub">
        Add friends to see who has a spare copy of <strong>{{ cardName }}</strong>.
        Vaultkeeper is social — the matcher works as soon as you connect.
      </p>
    </div>

    <!-- ── C4: Friends exist but all have visibility = private ────────── -->
    <div v-else-if="isNoVisibleFriendsState" class="wmp-state wmp-state--no-visible">
      <span class="wmp-state-icon" aria-hidden="true">🔒</span>
      <p class="wmp-state-text wmp-state-text--lead">Collections are private</p>
      <p class="wmp-state-text wmp-state-text--sub">
        Your friends haven't shared their collections yet. Ask them to set
        <em>collection visibility</em> to "Friends" in their privacy settings.
      </p>
    </div>

    <!-- ── C4: Friend revoked visibility mid-session ─────────────────── -->
    <div v-else-if="isVisibilityRevokedState" class="wmp-state wmp-state--revoked">
      <span class="wmp-state-icon wmp-state-icon--revoked" aria-hidden="true">🔕</span>
      <p class="wmp-state-text wmp-state-text--lead">Visibility changed</p>
      <p class="wmp-state-text wmp-state-text--sub">
        A friend changed their collection visibility — matches may be stale.
        Use the Refresh button in the tab header to reload.
      </p>
    </div>

    <!-- ── C4: 0 matches for THIS card (friends are visible, just don't
         have it) — the original generic no-match state ──────────────── -->
    <div v-else-if="isNoCardMatchState" class="wmp-state">
      <span class="wmp-state-icon" aria-hidden="true">👥</span>
      <p class="wmp-state-text wmp-state-text--lead">No matches found</p>
      <p class="wmp-state-text wmp-state-text--sub">
        None of your friends have an available copy of <strong>{{ cardName }}</strong>.
        Try trading or sourcing it from a local game store.
      </p>
    </div>

    <!-- ── Friend list ───────────────────────────────────────────────── -->
    <template v-else>
      <!-- C5: No-reservation notice — shown above the list so users see it
           before they start planning to acquire a card. Compact mode fits
           in the narrow panel without dominating. -->
      <NoReservationNotice class="wmp-reservation-notice" compact />

      <ul class="wmp-friend-list">
        <li
          v-for="friend in friends"
          :key="friend.user_id"
          class="wmp-friend"
        >
          <!-- Friend row header: avatar + username + copy username button -->
          <div class="wmp-friend-header">
            <span
              class="wmp-avatar"
              :style="{ background: avatarColor(friend.username) }"
              aria-hidden="true"
            >{{ avatarInitials(friend.username) }}</span>

            <span class="wmp-username">{{ friend.username }}</span>

            <button
              type="button"
              class="wmp-copy-btn"
              :class="{ 'wmp-copy-btn--done': copiedUserId === friend.user_id }"
              :title="copiedUserId === friend.user_id ? 'Copied!' : 'Copy username to clipboard'"
              @click="copyUsername(friend)"
            >
              {{ copiedUserId === friend.user_id ? '✓' : 'Copy' }}
            </button>
          </div>

          <!-- Available copies for this friend -->
          <ul class="wmp-copies">
            <li
              v-for="copy in friend.available_copies"
              :key="copy.collection_entry_id"
              class="wmp-copy"
            >
              <ConditionBadge :value="copy.condition" />
              <span v-if="copy.foil" class="wmp-foil-badge">Foil</span>
              <span class="wmp-location">{{ copy.location_name }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </template>
  </aside>
</template>

<style scoped>
/* ── Shell ─────────────────────────────────────────────────────────── */
/* Fixed slide-in over the right rail so any tab can open it without
   competing for layout space with the tab system or detail sidebars.
   z-index sits above the right rail (CatalogDetailSidebar / DeckDetailSidebar)
   so opening a match panel temporarily overlays whichever detail was visible. */
.wmp {
  position: fixed;
  top: 56px; /* topbar height */
  right: 0;
  bottom: 0;
  width: var(--detail-width, 340px);
  background: var(--bg-1);
  border-left: 1px solid var(--hairline, #33312c);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 50;
  box-shadow: -8px 0 24px rgba(0, 0, 0, 0.35);
  animation: wmp-slide 160ms ease-out;
}
@keyframes wmp-slide {
  from { transform: translateX(100%); }
  to   { transform: translateX(0); }
}

/* ── Header ────────────────────────────────────────────────────────── */
.wmp-header {
  flex-shrink: 0;
  padding: 10px 14px 8px;
  border-bottom: 1px solid var(--hairline, #33312c);
}
.wmp-title-row {
  display: flex;
  align-items: center;
  gap: 6px;
}
.wmp-title {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-100);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-family: var(--font-sans), sans-serif;
}
.wmp-subtitle {
  margin: 4px 0 0;
  font-size: 11px;
  color: var(--ink-50);
  font-family: var(--font-sans), sans-serif;
}
.wmp-close {
  background: transparent;
  border: 0;
  color: var(--ink-50);
  width: 26px;
  height: 26px;
  border-radius: var(--radius-sm, 4px);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  cursor: pointer;
  padding: 0;
  flex-shrink: 0;
  transition: background 0.1s, color 0.1s;
}
.wmp-close:hover { background: var(--bg-2); color: var(--ink-100); }

/* ── State views (loading / error / empty) ─────────────────────────── */
.wmp-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 2rem 1.5rem;
  text-align: center;
}
.wmp-state--error .wmp-state-icon { color: var(--cond-hp, #d97757); }
/* Revoked state: amber tint to signal "something changed" non-destructively */
.wmp-state--revoked { background: color-mix(in srgb, var(--amber, #c9a552) 4%, transparent); }
.wmp-state-icon--revoked { color: var(--amber, #c9a552); }
/* No-friends state: soft nudge tone */
.wmp-state--no-friends .wmp-state-text--lead { color: var(--amber, #c9a552); }
/* No-visible-friends: lock icon is neutral, text is muted */
.wmp-state--no-visible .wmp-state-icon { color: var(--ink-30); }
.wmp-state-icon { font-size: 28px; line-height: 1; }
.wmp-state-text {
  font-size: 13px;
  color: var(--ink-70, #a8a396);
  font-family: var(--font-sans), sans-serif;
  line-height: 1.45;
  margin: 0;
}
.wmp-state-text--lead {
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-100);
}
.wmp-state-text--sub { font-size: 12px; color: var(--ink-50); }
.wmp-state-text strong { color: var(--ink-100); font-weight: 600; }

.wmp-spinner {
  display: inline-block;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid var(--hairline, #33312c);
  border-top-color: var(--amber, #c9a552);
  animation: wmp-spin 0.8s linear infinite;
}
@keyframes wmp-spin { to { transform: rotate(360deg); } }

/* ── Friend list ───────────────────────────────────────────────────── */
.wmp-friend-list {
  flex: 1;
  overflow-y: auto;
  list-style: none;
  margin: 0;
  padding: 0;
}
.wmp-friend {
  padding: 12px 14px 10px;
  border-bottom: 1px solid var(--hairline, #33312c);
}
.wmp-friend:last-child { border-bottom: 0; }

.wmp-friend-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.wmp-avatar {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  font-family: var(--font-sans), sans-serif;
  user-select: none;
}
.wmp-username {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-100);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-family: var(--font-sans), sans-serif;
}
.wmp-copy-btn {
  flex-shrink: 0;
  background: var(--bg-0);
  border: 1px solid var(--hairline, #33312c);
  color: var(--ink-70);
  font-size: 11px;
  font-family: var(--font-sans), sans-serif;
  padding: 3px 8px;
  border-radius: var(--radius-sm, 4px);
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s, color 0.1s;
  white-space: nowrap;
}
.wmp-copy-btn:hover {
  background: var(--bg-2);
  border-color: var(--amber-lo, #8a7436);
  color: var(--ink-100);
}
.wmp-copy-btn--done {
  border-color: var(--cond-nm, #4caf50);
  color: var(--cond-nm, #4caf50);
}

/* ── Copies ────────────────────────────────────────────────────────── */
.wmp-copies {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.wmp-copy {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--ink-70);
  font-family: var(--font-sans), sans-serif;
}
.wmp-foil-badge {
  display: inline-block;
  font-size: 9px;
  font-weight: 700;
  padding: 2px 4px;
  border-radius: 4px;
  color: #fff;
  background: linear-gradient(135deg, #a855f7, #3b82f6, #3ec97c);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
  line-height: 1;
}
.wmp-location {
  color: var(--ink-50);
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Reservation notice (C5) — sits above the friend list ──────────── */
.wmp-reservation-notice {
  flex-shrink: 0;
  margin: 8px 14px 0;
  border-radius: 5px;
}
</style>
