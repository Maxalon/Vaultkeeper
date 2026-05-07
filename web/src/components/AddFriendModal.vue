<script setup>
import { ref, watch, onUnmounted } from 'vue'
import { useFriendsStore } from '../stores/friends'
import { useToast } from '../composables/useToast'

const emit = defineEmits(['close', 'sent'])

const friends = useFriendsStore()
const toast = useToast()

const query = ref('')
const sending = ref(false)
const sentTo = ref(new Set())

// Debounced search — 300 ms after the user stops typing
let debounceTimer = null
watch(query, (val) => {
  clearTimeout(debounceTimer)
  if (!val.trim()) {
    friends.clearSearch()
    return
  }
  debounceTimer = setTimeout(() => {
    friends.searchUsers(val)
  }, 300)
})

onUnmounted(() => {
  clearTimeout(debounceTimer)
  friends.clearSearch()
})

function closeOnBackdrop(e) {
  if (e.target === e.currentTarget) emit('close')
}

async function sendRequest(user) {
  if (sentTo.value.has(user.id)) return
  sending.value = true
  try {
    await friends.sendRequest(user.username)
    sentTo.value = new Set([...sentTo.value, user.id])
    toast.success(`Friend request sent to ${user.username}.`)
    emit('sent')
  } catch (e) {
    const status = e.response?.status
    if (status === 409) {
      toast.error(`A request to ${user.username} already exists.`)
    } else {
      toast.error(e.response?.data?.message || 'Failed to send request')
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="backdrop" @click="closeOnBackdrop" role="dialog" aria-modal="true" aria-label="Add Friend">
      <div class="modal">
        <div class="modal-header">
          <h2 class="modal-title">Add Friend</h2>
          <button class="close-btn" @click="$emit('close')" aria-label="Close">×</button>
        </div>

        <p class="modal-lede">
          Search by username. Friends can see your collection and decks
          according to your privacy settings.
        </p>

        <div class="search-wrap">
          <input
            v-model="query"
            type="text"
            placeholder="Search username…"
            class="search-input"
            autocomplete="off"
            autofocus
          />
          <span v-if="friends.searchLoading" class="search-spinner" aria-hidden="true" />
        </div>

        <!-- Results -->
        <ul v-if="friends.searchResults.length" class="results">
          <li
            v-for="user in friends.searchResults"
            :key="user.id"
            class="result-row"
          >
            <div class="user-avatar" aria-hidden="true">
              {{ user.username.charAt(0).toUpperCase() }}
            </div>
            <span class="user-username">{{ user.username }}</span>
            <button
              class="send-btn"
              :disabled="sending || sentTo.has(user.id)"
              @click="sendRequest(user)"
            >
              <template v-if="sentTo.has(user.id)">Sent ✓</template>
              <template v-else>Send Request</template>
            </button>
          </li>
        </ul>

        <div
          v-else-if="query.trim() && !friends.searchLoading"
          class="no-results"
        >
          No users found for "{{ query }}"
        </div>

        <div
          v-else-if="!query.trim()"
          class="hint"
        >
          Type a username to search.
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  display: grid;
  place-items: center;
  z-index: 9000;
}

.modal {
  width: 480px;
  max-width: calc(100vw - 32px);
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  box-shadow: 0 24px 72px rgba(0, 0, 0, 0.7);
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--hairline);
}

.modal-title {
  font-family: var(--font-display), serif;
  font-size: 20px;
  font-weight: 400;
  color: var(--amber);
  margin: 0;
  letter-spacing: -0.01em;
}

.close-btn {
  width: 28px;
  height: 28px;
  background: transparent;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-50);
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  transition: all 0.1s ease;
}
.close-btn:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-3);
}

.modal-lede {
  font-size: 13px;
  color: var(--ink-50);
  line-height: 1.5;
  margin: 0;
  padding: 12px 20px 0;
}

.search-wrap {
  position: relative;
  padding: 12px 20px;
  border-bottom: 1px solid var(--hairline);
}

.search-input {
  width: 100%;
  height: 36px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 13px;
  padding: 0 36px 0 12px;
  outline: none;
  box-sizing: border-box;
  transition: border-color 0.12s ease;
}
.search-input:focus { border-color: var(--amber); }
.search-input::placeholder { color: var(--ink-30); }

.search-spinner {
  position: absolute;
  right: 32px;
  top: 50%;
  transform: translateY(-50%);
  width: 14px;
  height: 14px;
  border: 2px solid var(--hairline);
  border-top-color: var(--amber);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: translateY(-50%) rotate(360deg); }
}

.results {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 280px;
  overflow-y: auto;
}

.result-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 20px;
  border-bottom: 1px solid var(--hairline);
  transition: background 0.1s ease;
}
.result-row:last-child { border-bottom: none; }
.result-row:hover { background: var(--bg-3); }

.user-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: color-mix(in oklab, var(--amber) 20%, var(--bg-3));
  border: 1px solid color-mix(in oklab, var(--amber) 30%, var(--hairline));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 600;
  color: var(--amber);
  flex-shrink: 0;
}

.user-username {
  flex: 1;
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-100);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.send-btn {
  height: 26px;
  padding: 0 12px;
  background: var(--amber);
  color: #1a1408;
  border: none;
  border-radius: var(--radius-sm);
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.12s ease;
  flex-shrink: 0;
  white-space: nowrap;
}
.send-btn:hover:not(:disabled) { opacity: 0.85; }
.send-btn:disabled {
  background: var(--bg-3);
  color: var(--ink-50);
  cursor: not-allowed;
  border: 1px solid var(--hairline);
}

.no-results,
.hint {
  padding: 24px 20px;
  text-align: center;
  color: var(--ink-50);
  font-size: 13px;
}
</style>
