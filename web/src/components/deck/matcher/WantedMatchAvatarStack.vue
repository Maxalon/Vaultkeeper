<script setup>
/**
 * WantedMatchAvatarStack — compact inline avatar stack shown per wanted
 * deck row in DeckGrid. Each avatar represents one friend who has an
 * available copy of that card.
 *
 * Kept deliberately cheap: no watchers, no async, pure presentational.
 * DeckGrid renders one of these per wanted row so allocations matter.
 *
 * Props
 *  friends  — array of { user_id, username, available_copies[] } from the
 *             /decks/{deck}/wanted-matches response shape (plan §API Contract)
 *  loading  — true while the parent store is fetching matches
 *  maxShown — max avatars to render before "+N more" overflow pill
 *
 * Emits
 *  open  — user clicked the stack; parent should open WantedMatchPanel for
 *          this card
 */

import { computed } from 'vue'

const props = defineProps({
  friends: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  maxShown: {
    type: Number,
    default: 3,
  },
})

const emit = defineEmits(['open'])

const shown = computed(() => props.friends.slice(0, props.maxShown))
const overflow = computed(() => Math.max(0, props.friends.length - props.maxShown))

/**
 * Deterministic background colour from username. Uses the same djb2-style
 * hash as the existing CornerCountBadge palette. Keeps renders consistent
 * across sessions without any server round-trip.
 */
const AVATAR_PALETTE = [
  '#5b6ee1', // indigo
  '#37946e', // forest
  '#8b6914', // amber-dark
  '#9e3030', // crimson
  '#4e7fa0', // steel
  '#7b5ea7', // purple
  '#3d7a5f', // teal
  '#b85c38', // rust
]

function avatarColor(username) {
  if (!username) return AVATAR_PALETTE[0]
  let h = 5381
  for (let i = 0; i < username.length; i++) {
    h = ((h << 5) + h) ^ username.charCodeAt(i)
    h = h >>> 0
  }
  return AVATAR_PALETTE[h % AVATAR_PALETTE.length]
}

function initials(username) {
  if (!username) return '?'
  return username.slice(0, 2).toUpperCase()
}

function totalCopies(friend) {
  return friend.available_copies?.reduce((s, c) => s + 1, 0) ?? 0
}

function stackTitle(friends) {
  if (!friends.length) return 'No friends have this card available'
  const names = friends.map((f) => f.username).join(', ')
  return `Available from: ${names}. Click to see details.`
}
</script>

<template>
  <!-- Loading shimmer — single pill so the layout doesn't jump -->
  <span v-if="loading" class="wmas-loading" aria-label="Checking friend matches…" />

  <!-- No friends have a copy — render nothing (parent controls visibility) -->
  <template v-else-if="friends.length === 0" />

  <!-- One or more friends — avatar stack + overflow pill -->
  <button
    v-else
    type="button"
    class="wmas-stack"
    :title="stackTitle(friends)"
    :aria-label="`${friends.length} friend${friends.length === 1 ? '' : 's'} have this card`"
    @click.stop="emit('open')"
  >
    <span
      v-for="(friend, i) in shown"
      :key="friend.user_id"
      class="wmas-avatar"
      :style="{
        background: avatarColor(friend.username),
        zIndex: shown.length - i,
        marginLeft: i === 0 ? '0' : '-6px',
      }"
      :title="`${friend.username} (${totalCopies(friend)} copy)`"
    >{{ initials(friend.username) }}</span>

    <span
      v-if="overflow > 0"
      class="wmas-overflow"
      :style="{ marginLeft: '-6px' }"
    >+{{ overflow }}</span>
  </button>
</template>

<style scoped>
/* Inline flex so the stack sits flush inside whatever row element the
   parent places it in. pointer-events: all so clicks propagate only to
   the button itself (not the card strip behind it). */
.wmas-stack {
  display: inline-flex;
  align-items: center;
  background: transparent;
  border: 0;
  padding: 0;
  cursor: pointer;
  outline: none;
  /* Let the parent control gap/offset from surrounding text. */
  vertical-align: middle;
  /* A subtle focus ring for keyboard users that doesn't clash with the
     amber outlines already used by strips. */
  border-radius: 999px;
}
.wmas-stack:focus-visible {
  box-shadow: 0 0 0 2px var(--amber, #c9a552);
}

.wmas-avatar,
.wmas-overflow {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  font-size: 8px;
  font-weight: 700;
  font-family: var(--font-sans), sans-serif;
  line-height: 1;
  letter-spacing: 0.02em;
  color: #fff;
  /* Hairline ring so overlapping avatars read as separate. Uses the
     bg-0 colour so it blends with both the strip and tile backgrounds. */
  box-shadow: 0 0 0 1.5px var(--bg-0, #1a1610);
  user-select: none;
  flex-shrink: 0;
}

.wmas-overflow {
  background: var(--bg-2, #2e2b26);
  color: var(--ink-70, #a8a396);
  font-size: 7.5px;
}

/* Subtle pulse while loading so the user knows a fetch is in flight. */
.wmas-loading {
  display: inline-block;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--bg-2, #2e2b26);
  opacity: 0.6;
  animation: wmas-pulse 1.2s ease-in-out infinite;
  vertical-align: middle;
}

@keyframes wmas-pulse {
  0%, 100% { opacity: 0.6; }
  50% { opacity: 0.2; }
}
</style>
