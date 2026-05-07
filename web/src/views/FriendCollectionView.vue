<script setup>
/**
 * FriendCollectionView — read-only view of a friend's collection.
 *
 * Per the plan: extend CollectionView with readOnly + userId props rather
 * than duplicating. This view reuses CardListPanel and DetailSidebar via
 * a separate Pinia store instance (useCollectionStore has no $id isolation),
 * so instead we use a lightweight approach: we provision the data ourselves
 * and render the same presentation layer, but block all mutation UI.
 *
 * The store's activeEntryId / setActiveEntry still work read-only because
 * they only fetch detail data, they don't write. The mutation actions
 * (updateEntry, deleteEntry, batchMove) are never called since we hide
 * all write controls via `readOnly` prop passed to the child components.
 */
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useFriendsStore } from '../stores/friends'
import { useSettingsStore } from '../stores/settings'
import { useToast } from '../composables/useToast'
import api from '../lib/api'
import VaultMark from '../components/VaultMark.vue'

const props = defineProps({
  /** The friend's user id, from the route param. */
  userId: {
    type: Number,
    required: true,
  },
  /** Always true for this view — passed by the router. */
  readOnly: {
    type: Boolean,
    default: true,
  },
})

const router = useRouter()
const friends = useFriendsStore()
const settings = useSettingsStore()
const toast = useToast()

const entries = ref([])
const loading = ref(false)
const error = ref(null)
const activeEntryId = ref(null)
const sortKey = ref('name')
const sortOrder = ref('asc')
const search = ref('')

// The friend's info for the header
const friend = computed(() =>
  friends.friendById(props.userId),
)

onMounted(async () => {
  if (!friends.friends.length) {
    await friends.fetchFriends()
  }
  await fetchEntries()
})

async function fetchEntries() {
  loading.value = true
  error.value = null
  try {
    const params = {
      sort: sortKey.value,
      order: sortOrder.value,
    }
    const q = search.value.trim()
    if (q) params.q = q
    const { data } = await api.get(`/users/${props.userId}/collection`, { params })
    entries.value = data.data || []
  } catch (e) {
    const status = e.response?.status
    if (status === 403) {
      error.value = "This friend has made their collection private."
    } else if (status === 404) {
      error.value = "Friend not found."
    } else {
      error.value = e.response?.data?.message || 'Failed to load collection'
    }
    toast.error(error.value)
  } finally {
    loading.value = false
  }
}

const filteredEntries = computed(() => {
  if (sortKey.value !== 'color') return entries.value
  const WUBRG = { W: 0, U: 1, B: 2, R: 3, G: 4 }
  function colorKey(colors) {
    if (!colors || colors.length === 0) return '9'
    if (colors.length === 1) return '0' + WUBRG[colors[0]]
    return '' + colors.length + [...colors].sort((a, b) => WUBRG[a] - WUBRG[b]).map(c => WUBRG[c]).join('')
  }
  const dir = sortOrder.value === 'desc' ? -1 : 1
  return [...entries.value].sort((a, b) => {
    const ka = colorKey(a.card?.colors)
    const kb = colorKey(b.card?.colors)
    if (ka !== kb) return ka < kb ? -dir : dir
    const na = (a.card?.name || '').toLowerCase()
    const nb = (b.card?.name || '').toLowerCase()
    return na < nb ? -1 : na > nb ? 1 : 0
  })
})

function goBack() {
  router.push({ name: 'friends' })
}

let searchTimer = null
function onSearchInput(val) {
  search.value = val
  clearTimeout(searchTimer)
  searchTimer = setTimeout(fetchEntries, 250)
}
</script>

<template>
  <main class="friend-collection-page">
    <header class="fc-header">
      <VaultMark />
      <button class="back" @click="goBack">← Friends</button>
    </header>

    <section class="fc-content">
      <div class="fc-title-row">
        <div class="fc-title-block">
          <h1 class="title">
            <span class="friend-avatar-lg" aria-hidden="true">
              {{ (friend?.username || '?').charAt(0).toUpperCase() }}
            </span>
            {{ friend?.username ?? `User ${userId}` }}'s Collection
          </h1>
          <p class="lede">Read-only view. You can browse but not modify.</p>
        </div>
      </div>

      <!-- Search bar -->
      <div class="search-bar-wrap">
        <input
          type="text"
          :value="search"
          placeholder="Search cards…"
          class="search-input"
          @input="onSearchInput($event.target.value)"
        />
      </div>

      <!-- Error state -->
      <div v-if="error" class="state-msg error">
        {{ error }}
      </div>

      <!-- Loading state -->
      <div v-else-if="loading" class="state-msg">
        Loading collection…
      </div>

      <!-- Empty state -->
      <div
        v-else-if="!filteredEntries.length"
        class="state-msg"
      >
        No cards found.
      </div>

      <!-- Card list (read-only) -->
      <ul v-else class="card-list">
        <li
          v-for="entry in filteredEntries"
          :key="entry.id"
          class="card-row"
        >
          <div class="card-art-thumb">
            <img
              v-if="entry.card?.image_uri"
              :src="entry.card.image_uri"
              :alt="entry.card?.name"
              loading="lazy"
              class="card-thumb-img"
            />
            <span v-else class="card-thumb-placeholder" aria-hidden="true">?</span>
          </div>
          <div class="card-info">
            <span class="card-name">{{ entry.card?.name ?? '—' }}</span>
            <span class="card-meta">
              {{ entry.card?.set?.toUpperCase() }} ·
              {{ entry.condition }}
              <template v-if="entry.foil"> · Foil</template>
            </span>
          </div>
          <span class="card-qty">×{{ entry.quantity }}</span>
          <span class="card-location">{{ entry.location_name }}</span>
        </li>
      </ul>
    </section>
  </main>
</template>

<style scoped>
.friend-collection-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  padding: 32px 48px 64px;
}

.fc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 900px;
  margin: 0 auto 40px;
}

.back {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  height: 32px;
  padding: 0 14px;
  border-radius: var(--radius-sm);
  font-size: 12px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.back:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-1);
}

.fc-content {
  max-width: 900px;
  margin: 0 auto;
}

.fc-title-row {
  margin-bottom: 20px;
}

.title {
  font-family: var(--font-display), serif;
  font-size: 28px;
  font-weight: 400;
  letter-spacing: -0.02em;
  color: var(--ink-100);
  margin: 0 0 6px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.friend-avatar-lg {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: color-mix(in oklab, var(--amber) 20%, var(--bg-2));
  border: 1px solid color-mix(in oklab, var(--amber) 30%, var(--hairline));
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 600;
  color: var(--amber);
  flex-shrink: 0;
}

.lede {
  font-size: 13px;
  color: var(--ink-50);
  margin: 0;
}

.search-bar-wrap {
  margin-bottom: 16px;
}

.search-input {
  width: 100%;
  max-width: 400px;
  height: 36px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  padding: 0 12px;
  outline: none;
  box-sizing: border-box;
  transition: border-color 0.12s ease;
}
.search-input:focus { border-color: var(--amber); }
.search-input::placeholder { color: var(--ink-30); }

.state-msg {
  padding: 48px 20px;
  text-align: center;
  color: var(--ink-50);
  font-size: 14px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
}
.state-msg.error { color: #d46a6a; }

.card-list {
  list-style: none;
  margin: 0;
  padding: 0;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.card-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 16px;
  border-bottom: 1px solid var(--hairline);
  transition: background 0.1s ease;
}
.card-row:last-child { border-bottom: none; }
.card-row:hover { background: var(--bg-2); }

.card-art-thumb {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  overflow: hidden;
  flex-shrink: 0;
  background: var(--bg-2);
  display: flex;
  align-items: center;
  justify-content: center;
}

.card-thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.card-thumb-placeholder {
  font-size: 16px;
  color: var(--ink-30);
}

.card-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.card-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-100);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-meta {
  font-size: 11px;
  color: var(--ink-50);
}

.card-qty {
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-70);
  flex-shrink: 0;
}

.card-location {
  font-size: 11px;
  color: var(--ink-30);
  flex-shrink: 0;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
