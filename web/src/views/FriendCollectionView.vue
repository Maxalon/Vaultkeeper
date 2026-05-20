<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useFriendsStore } from '../stores/friends'
import { useToast } from '../composables/useToast'
import api from '../lib/api'
import VaultMark from '../components/VaultMark.vue'
import SearchInput from '../components/SearchInput.vue'
import StateMessage from '../components/StateMessage.vue'
import CardListItem from '../components/CardListItem.vue'
import CardDetailBody from '../components/CardDetailBody.vue'
import { compareByColorThenName } from '../utils/cardSort'

const props = defineProps({
  userId: { type: Number, required: true },
})

const router = useRouter()
const friends = useFriendsStore()
const toast = useToast()

const entries = ref([])
const loading = ref(false)
const error = ref(null)
const restricted = ref(false)
const sortKey = ref('name')
const sortOrder = ref('asc')
const search = ref('')
const activeEntry = ref(null)

const friend = computed(() => friends.friendById(props.userId))

onMounted(async () => {
  if (!friends.friends.length) await friends.fetchFriends()
  await fetchEntries()
  document.addEventListener('keydown', onKeyDown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeyDown)
})

async function fetchEntries() {
  loading.value = true
  error.value = null
  restricted.value = false
  try {
    const params = { sort: sortKey.value, order: sortOrder.value }
    const q = search.value.trim()
    if (q) params.q = q
    const { data } = await api.get(`/users/${props.userId}/collection`, { params })
    entries.value = data.data || []
  } catch (e) {
    const status = e.response?.status
    if (status === 403) {
      restricted.value = true
      error.value = 'This friend has restricted their collection visibility.'
    } else if (status === 404) {
      error.value = 'Friend not found.'
      toast.error(error.value)
    } else {
      error.value = e.response?.data?.message || 'Failed to load collection'
      toast.error(error.value)
    }
  } finally {
    loading.value = false
  }
}

const filteredEntries = computed(() => {
  if (sortKey.value !== 'color') return entries.value
  const dir = sortOrder.value === 'desc' ? -1 : 1
  return [...entries.value].sort((a, b) => compareByColorThenName(a, b, dir))
})

function goBack() { router.push({ name: 'friends' }) }

function openDetail(entry) { activeEntry.value = entry }
function closeDetail() { activeEntry.value = null }

function onKeyDown(e) {
  if (e.key === 'Escape' && activeEntry.value) closeDetail()
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
        <h1 class="title">
          <span class="friend-avatar-lg" aria-hidden="true">
            {{ (friend?.username || '?').charAt(0).toUpperCase() }}
          </span>
          {{ friend?.username ?? `User ${userId}` }}'s Collection
        </h1>
        <p class="lede">Read-only view. You can browse but not modify.</p>
      </div>

      <div class="search-bar-wrap">
        <SearchInput
          v-model="search"
          placeholder="Search cards…"
          @debounced="fetchEntries"
        />
      </div>

      <template v-if="error">
        <StateMessage variant="error">{{ error }}</StateMessage>
        <div v-if="restricted" class="restricted-back">
          <button class="back-inline" @click="goBack">← Back to Friends</button>
        </div>
      </template>
      <StateMessage v-else-if="loading">Loading collection…</StateMessage>
      <StateMessage v-else-if="!filteredEntries.length">No cards found.</StateMessage>

      <ul v-else class="card-list">
        <CardListItem
          v-for="entry in filteredEntries"
          :key="entry.id"
          :entry="entry"
          class="card-row-clickable"
          @click="openDetail(entry)"
        />
      </ul>
    </section>

    <!-- Read-only card detail sheet -->
    <div v-if="activeEntry" class="fc-sheet-backdrop" @click.self="closeDetail">
      <div class="fc-sheet" role="dialog" aria-modal="true" :aria-label="activeEntry.card?.name">
        <header class="fc-sheet-header">
          <span class="fc-sheet-read-only">Read-only</span>
          <button class="fc-sheet-close" @click="closeDetail" aria-label="Close">✕</button>
        </header>
        <div class="fc-sheet-body">
          <CardDetailBody v-if="activeEntry.card" :card="activeEntry.card" />
          <div class="fc-sheet-copy-meta">
            <div class="fc-copy-row">
              <span class="fc-copy-label">Condition</span>
              <span class="fc-copy-value">{{ activeEntry.condition }}</span>
            </div>
            <div class="fc-copy-row">
              <span class="fc-copy-label">Finish</span>
              <span class="fc-copy-value">
                {{ activeEntry.is_etched ? 'Etched' : activeEntry.foil ? 'Foil' : 'Nonfoil' }}
              </span>
            </div>
            <div class="fc-copy-row">
              <span class="fc-copy-label">Quantity</span>
              <span class="fc-copy-value">{{ activeEntry.quantity }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
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

.search-bar-wrap { margin-bottom: 16px; }

.restricted-back {
  margin-top: 16px;
  text-align: center;
}

.back-inline {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  height: 32px;
  padding: 0 16px;
  border-radius: var(--radius-sm);
  font-size: 12px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.back-inline:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-1);
}

.card-row-clickable {
  cursor: pointer;
}

.card-list {
  list-style: none;
  margin: 0;
  padding: 0;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

/* Read-only detail sheet */
.fc-sheet-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 200;
  padding: 0;
}

.fc-sheet {
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  background: var(--bg-1);
  border-top: 1px solid var(--hairline);
  border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.fc-sheet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  border-bottom: 1px solid var(--hairline);
  flex-shrink: 0;
}

.fc-sheet-read-only {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--ink-50);
}

.fc-sheet-close {
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
.fc-sheet-close:hover { background: var(--bg-2); color: var(--ink-100); }

.fc-sheet-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 18px 24px;
}

.fc-sheet-copy-meta {
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--hairline);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.fc-copy-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
}

.fc-copy-label {
  color: var(--ink-50);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
}

.fc-copy-value {
  color: var(--ink-100);
  font-size: 13px;
}
</style>
