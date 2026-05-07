<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useFriendsStore } from '../stores/friends'
import { useToast } from '../composables/useToast'
import api from '../lib/api'
import VaultMark from '../components/VaultMark.vue'
import SearchInput from '../components/SearchInput.vue'
import StateMessage from '../components/StateMessage.vue'
import CardListItem from '../components/CardListItem.vue'
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
const sortKey = ref('name')
const sortOrder = ref('asc')
const search = ref('')

const friend = computed(() => friends.friendById(props.userId))

onMounted(async () => {
  if (!friends.friends.length) await friends.fetchFriends()
  await fetchEntries()
})

async function fetchEntries() {
  loading.value = true
  error.value = null
  try {
    const params = { sort: sortKey.value, order: sortOrder.value }
    const q = search.value.trim()
    if (q) params.q = q
    const { data } = await api.get(`/users/${props.userId}/collection`, { params })
    entries.value = data.data || []
  } catch (e) {
    const status = e.response?.status
    if (status === 403) error.value = 'This friend has made their collection private.'
    else if (status === 404) error.value = 'Friend not found.'
    else error.value = e.response?.data?.message || 'Failed to load collection'
    toast.error(error.value)
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

      <StateMessage v-if="error" variant="error">{{ error }}</StateMessage>
      <StateMessage v-else-if="loading">Loading collection…</StateMessage>
      <StateMessage v-else-if="!filteredEntries.length">No cards found.</StateMessage>

      <ul v-else class="card-list">
        <CardListItem
          v-for="entry in filteredEntries"
          :key="entry.id"
          :entry="entry"
        />
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

.search-bar-wrap { margin-bottom: 16px; }

.card-list {
  list-style: none;
  margin: 0;
  padding: 0;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}
</style>
