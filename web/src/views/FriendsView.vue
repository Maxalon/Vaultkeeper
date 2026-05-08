<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useFriendsStore } from '../stores/friends'
import { useToast } from '../composables/useToast'
import AddFriendModal from '../components/AddFriendModal.vue'
import VaultMark from '../components/VaultMark.vue'

const router = useRouter()
const friends = useFriendsStore()
const toast = useToast()

const tab = ref('friends') // 'friends' | 'requests'
const showAddModal = ref(false)
const respondingId = ref(null)

onMounted(async () => {
  await Promise.all([friends.fetchFriends(), friends.fetchRequests()])
})

function goBack() {
  const target = window.history.state?.returnTo
  if (target && typeof target === 'string') router.push(target)
  else router.push('/collection')
}

async function acceptRequest(request) {
  respondingId.value = request.id
  try {
    await friends.respondToRequest(request.id, 'accept')
    toast.success(`You and ${request.user.username} are now friends.`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to accept request')
  } finally {
    respondingId.value = null
  }
}

async function declineRequest(request) {
  respondingId.value = request.id
  try {
    await friends.respondToRequest(request.id, 'decline')
    toast.info('Request declined.')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to decline request')
  } finally {
    respondingId.value = null
  }
}

async function cancelRequest(request) {
  respondingId.value = request.id
  try {
    await friends.cancelRequest(request.id)
    toast.info('Request cancelled.')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to cancel request')
  } finally {
    respondingId.value = null
  }
}

async function unfriend(friend) {
  try {
    await friends.unfriend(friend.id)
    toast.info(`Removed ${friend.username} from friends.`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to unfriend')
  }
}

function viewFriendCollection(friend) {
  router.push({ name: 'friend-collection', params: { userId: friend.id } })
}
</script>

<template>
  <main class="friends-page">
    <header class="friends-header">
      <VaultMark />
      <button class="back" @click="goBack">← Back</button>
    </header>

    <section class="friends-content">
      <div class="page-top">
        <div class="page-title-row">
          <h1 class="title">Friends</h1>
          <button class="add-btn" @click="showAddModal = true">+ Add Friend</button>
        </div>
        <p class="lede">
          Connect with other collectors. Friends can see each other's collections
          and decks based on privacy settings.
        </p>
      </div>

      <!-- Tabs -->
      <div class="tabs" role="tablist">
        <button
          role="tab"
          :aria-selected="tab === 'friends'"
          :class="{ active: tab === 'friends' }"
          @click="tab = 'friends'"
        >
          Friends
          <span v-if="friends.friends.length" class="count">{{ friends.friends.length }}</span>
        </button>
        <button
          role="tab"
          :aria-selected="tab === 'requests'"
          :class="{ active: tab === 'requests' }"
          @click="tab = 'requests'"
        >
          Requests
          <span
            v-if="friends.pendingIncomingCount"
            class="count incoming"
          >{{ friends.pendingIncomingCount }}</span>
        </button>
      </div>

      <!-- Friends list -->
      <section v-if="tab === 'friends'" class="panel">
        <div v-if="friends.loading" class="empty">Loading…</div>
        <div v-else-if="!friends.friends.length" class="empty">
          No friends yet.
          <button class="inline-link" @click="showAddModal = true">Send a request</button>
          to get started.
        </div>
        <ul v-else class="friend-list">
          <li v-for="friend in friends.friends" :key="friend.id" class="friend-row">
            <div class="friend-avatar" aria-hidden="true">
              {{ friend.username.charAt(0).toUpperCase() }}
            </div>
            <div class="friend-info">
              <span class="friend-username">{{ friend.username }}</span>
              <span class="friend-since">friends since {{ new Date(friend.friends_since).toLocaleDateString() }}</span>
            </div>
            <div class="friend-actions">
              <button
                class="action-btn view"
                @click="viewFriendCollection(friend)"
                title="View collection"
              >
                View Collection
              </button>
              <button
                class="action-btn danger"
                @click="unfriend(friend)"
                title="Remove friend"
              >
                Unfriend
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Requests tab -->
      <section v-if="tab === 'requests'" class="panel">
        <!-- Incoming -->
        <div class="request-section">
          <h3 class="request-section-title">Incoming</h3>
          <div v-if="friends.loading" class="empty">Loading…</div>
          <div v-else-if="!friends.incomingRequests.length" class="empty">No incoming requests.</div>
          <ul v-else class="request-list">
            <li
              v-for="req in friends.incomingRequests"
              :key="req.id"
              class="request-row"
            >
              <div class="friend-avatar" aria-hidden="true">
                {{ req.user.username.charAt(0).toUpperCase() }}
              </div>
              <div class="friend-info">
                <span class="friend-username">{{ req.user.username }}</span>
                <span class="friend-since">
                  {{ new Date(req.created_at).toLocaleDateString() }}
                </span>
              </div>
              <div class="friend-actions">
                <button
                  class="action-btn primary"
                  :disabled="respondingId === req.id"
                  @click="acceptRequest(req)"
                >
                  Accept
                </button>
                <button
                  class="action-btn danger"
                  :disabled="respondingId === req.id"
                  @click="declineRequest(req)"
                >
                  Decline
                </button>
              </div>
            </li>
          </ul>
        </div>

        <!-- Outgoing -->
        <div class="request-section">
          <h3 class="request-section-title">Outgoing</h3>
          <div v-if="friends.loading" class="empty">Loading…</div>
          <div v-else-if="!friends.outgoingRequests.length" class="empty">No outgoing requests.</div>
          <ul v-else class="request-list">
            <li
              v-for="req in friends.outgoingRequests"
              :key="req.id"
              class="request-row"
            >
              <div class="friend-avatar" aria-hidden="true">
                {{ req.user.username.charAt(0).toUpperCase() }}
              </div>
              <div class="friend-info">
                <span class="friend-username">{{ req.user.username }}</span>
                <span class="friend-since">Pending · sent {{ new Date(req.created_at).toLocaleDateString() }}</span>
              </div>
              <div class="friend-actions">
                <button
                  class="action-btn danger"
                  :disabled="respondingId === req.id"
                  @click="cancelRequest(req)"
                >
                  Cancel
                </button>
              </div>
            </li>
          </ul>
        </div>
      </section>
    </section>

    <!-- Add friend modal -->
    <AddFriendModal
      v-if="showAddModal"
      @close="showAddModal = false"
      @sent="tab = 'requests'"
    />
  </main>
</template>

<style scoped>
.friends-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  padding: 32px 48px 64px;
}

.friends-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 720px;
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

.friends-content {
  max-width: 720px;
  margin: 0 auto;
}

.page-top { margin-bottom: 24px; }

.page-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.title {
  font-family: var(--font-display), serif;
  font-size: 36px;
  font-weight: 400;
  letter-spacing: -0.02em;
  color: var(--amber);
  margin: 0;
}

.lede {
  font-size: 14px;
  line-height: 1.5;
  color: var(--ink-70);
  margin: 0;
  max-width: 520px;
}

.add-btn {
  height: 32px;
  padding: 0 16px;
  background: var(--amber);
  color: #1a1408;
  border: none;
  border-radius: var(--radius-sm);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: opacity 0.12s ease;
  flex-shrink: 0;
}
.add-btn:hover { opacity: 0.85; }

/* Tabs */
.tabs {
  display: flex;
  gap: 2px;
  border-bottom: 1px solid var(--hairline);
  margin-bottom: 20px;
}
.tabs button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--ink-50);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: color 0.12s ease, border-color 0.12s ease;
  margin-bottom: -1px;
}
.tabs button:hover { color: var(--ink-100); }
.tabs button.active {
  color: var(--amber);
  border-bottom-color: var(--amber);
}

.count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  background: var(--bg-2);
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
  color: var(--ink-70);
}
.count.incoming {
  background: var(--amber);
  color: #1a1408;
}

.panel {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.empty {
  padding: 32px 20px;
  text-align: center;
  color: var(--ink-50);
  font-size: 14px;
}

.inline-link {
  background: none;
  border: none;
  color: var(--amber);
  font-size: 14px;
  cursor: pointer;
  padding: 0 2px;
  text-decoration: underline;
}

.friend-list,
.request-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.friend-row,
.request-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--hairline);
  transition: background 0.1s ease;
}
.friend-row:last-child,
.request-row:last-child { border-bottom: none; }
.friend-row:hover,
.request-row:hover { background: var(--bg-2); }

.friend-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: color-mix(in oklab, var(--amber) 20%, var(--bg-2));
  border: 1px solid color-mix(in oklab, var(--amber) 30%, var(--hairline));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 600;
  color: var(--amber);
  flex-shrink: 0;
}

.friend-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.friend-username {
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-100);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.friend-since {
  font-size: 11px;
  color: var(--ink-50);
}

.friend-actions {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.action-btn {
  height: 28px;
  padding: 0 12px;
  border-radius: var(--radius-sm);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  cursor: pointer;
  border: 1px solid var(--hairline);
  background: var(--bg-2);
  color: var(--ink-100);
  transition: all 0.12s ease;
}
.action-btn:hover:not(:disabled) { border-color: var(--ink-30); background: var(--bg-3); }
.action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.action-btn.primary {
  background: var(--amber);
  color: #1a1408;
  border-color: var(--amber);
}
.action-btn.primary:hover:not(:disabled) { opacity: 0.85; }

.action-btn.danger {
  color: #d46a6a;
  border-color: color-mix(in oklab, #d46a6a 40%, var(--hairline));
}
.action-btn.danger:hover:not(:disabled) {
  background: color-mix(in oklab, #d46a6a 15%, var(--bg-2));
  border-color: #d46a6a;
}

.action-btn.view {
  color: var(--ink-70);
}

/* Requests section headers */
.request-section { border-bottom: 1px solid var(--hairline); }
.request-section:last-child { border-bottom: none; }

.request-section-title {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0;
  padding: 10px 16px 8px;
  border-bottom: 1px solid var(--hairline);
}
</style>
