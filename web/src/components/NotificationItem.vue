<script setup>
/**
 * NotificationItem — renders a single notification row with its declarative
 * action buttons.
 *
 * Available: if action.available is false the button is hidden and a
 * "no longer available" tooltip is shown in its place.
 *
 * Clicking an action POSTs to the server-side gateway via the notifications
 * store — never calls the underlying endpoint directly.
 */
import { computed } from 'vue'
import { useNotificationsStore } from '../stores/notifications'
import { useToast } from '../composables/useToast'

const props = defineProps({
  notification: {
    type: Object,
    required: true,
  },
  /** The notification id currently executing an action (or null). */
  actionLoading: {
    type: Number,
    default: null,
  },
})

const notificationsStore = useNotificationsStore()
const toast = useToast()

const isRead = computed(() => !!props.notification.read_at)
const isActionLoading = computed(() => props.actionLoading === props.notification.id)

// Human-readable title from the notification type
const typeLabel = computed(() => {
  const map = {
    'friend.request_received': 'Friend Request',
    'friend.request_accepted': 'Friend Request Accepted',
    'collection.bulk_import_completed': 'Bulk Import Complete',
    'deck.card_marked_for_review': 'Card Marked for Review',
  }
  return map[props.notification.type] || props.notification.type
})

// A short description built from the payload
const description = computed(() => {
  const p = props.notification.payload || {}
  switch (props.notification.type) {
    case 'friend.request_received':
      return `${p.requester_username} sent you a friend request.`
    case 'friend.request_accepted':
      return `${p.acceptor_username} accepted your friend request.`
    case 'collection.bulk_import_completed':
      return `${p.card_count} card${p.card_count !== 1 ? 's' : ''} imported into ${p.location_name}.`
    case 'deck.card_marked_for_review':
      return `${p.card_name} in "${p.deck_name}" needs your attention.`
    default:
      return ''
  }
})

const availableActions = computed(() =>
  (props.notification.actions || []).filter((a) => a.available),
)

const unavailableActions = computed(() =>
  (props.notification.actions || []).filter((a) => !a.available),
)

async function handleAction(action) {
  if (isActionLoading.value) return
  try {
    await notificationsStore.runAction(props.notification.id, action.key)
    toast.success(`${action.label} completed.`)
  } catch (e) {
    const status = e.response?.status
    if (status === 409) {
      toast.error('This action is no longer available.')
      // Refetch to get updated available flags
      await notificationsStore.fetchNotifications()
    } else {
      toast.error(e.response?.data?.message || `Failed to run action: ${action.label}`)
    }
  }
}

async function handleClick() {
  if (!isRead.value) {
    await notificationsStore.markRead(props.notification.id)
  }
}
</script>

<template>
  <li
    class="notification-item"
    :class="{ unread: !isRead }"
    @click="handleClick"
  >
    <div class="item-body">
      <div class="item-meta">
        <span class="item-type">{{ typeLabel }}</span>
        <span class="item-time">
          {{ new Date(notification.created_at).toLocaleDateString() }}
        </span>
      </div>
      <p class="item-description">{{ description }}</p>

      <!-- Available actions -->
      <div
        v-if="availableActions.length || unavailableActions.length"
        class="item-actions"
      >
        <button
          v-for="action in availableActions"
          :key="action.key"
          class="action-btn"
          :class="{ danger: action.kind === 'danger', primary: action.kind === 'primary' }"
          :disabled="isActionLoading"
          @click.stop="handleAction(action)"
        >
          {{ action.label }}
        </button>

        <!-- Unavailable actions — show tooltip hint -->
        <span
          v-for="action in unavailableActions"
          :key="action.key"
          class="action-unavailable"
          :title="`This action is no longer available: the underlying record was changed or deleted.`"
        >
          {{ action.label }}
          <span class="unavailable-icon" aria-label="No longer available">✕</span>
        </span>
      </div>
    </div>

    <div class="unread-dot" v-if="!isRead" aria-label="Unread" />
  </li>
</template>

<style scoped>
.notification-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--hairline);
  cursor: pointer;
  transition: background 0.1s ease;
  position: relative;
}
.notification-item:last-child { border-bottom: none; }
.notification-item:hover { background: var(--bg-2); }
.notification-item.unread { background: color-mix(in oklab, var(--amber) 4%, var(--bg-1)); }
.notification-item.unread:hover { background: color-mix(in oklab, var(--amber) 7%, var(--bg-2)); }

.item-body {
  flex: 1;
  min-width: 0;
}

.item-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
  gap: 8px;
}

.item-type {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--amber);
}

.item-time {
  font-size: 11px;
  color: var(--ink-30);
  flex-shrink: 0;
}

.item-description {
  font-size: 13px;
  color: var(--ink-70);
  margin: 0 0 10px;
  line-height: 1.5;
}

.item-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.action-btn {
  height: 26px;
  padding: 0 12px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--ink-100);
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.1s ease;
}
.action-btn:hover:not(:disabled) {
  border-color: var(--ink-30);
  background: var(--bg-3);
}
.action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.action-btn.primary {
  background: var(--amber);
  border-color: var(--amber);
  color: #1a1408;
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

.action-unavailable {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 26px;
  padding: 0 10px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  font-size: 11px;
  color: var(--ink-30);
  cursor: help;
  text-decoration: line-through;
}

.unavailable-icon {
  font-size: 9px;
  color: var(--ink-30);
}

.unread-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--amber);
  flex-shrink: 0;
  margin-top: 4px;
}
</style>
