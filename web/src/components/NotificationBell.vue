<script setup>
/**
 * NotificationBell — bell icon with unread badge, dropdown preview list,
 * and "See all" link to /notifications.
 *
 * Mount this in AppTopBar (or equivalent) next to the settings icon.
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '../stores/notifications'
import NotificationItem from './NotificationItem.vue'
import IconBell from '../assets/icons/bell.svg'

const router = useRouter()
const notificationsStore = useNotificationsStore()

const open = ref(false)
const bellWrapRef = ref(null)

// Fetch notifications on mount and poll every 60 s for unread count
let pollTimer = null
onMounted(async () => {
  await notificationsStore.fetchNotifications()
  pollTimer = setInterval(() => {
    notificationsStore.fetchNotifications()
  }, 60_000)
  document.addEventListener('click', handleOutsideClick)
})
onUnmounted(() => {
  clearInterval(pollTimer)
  document.removeEventListener('click', handleOutsideClick)
})

function handleOutsideClick(e) {
  if (open.value && bellWrapRef.value && !bellWrapRef.value.contains(e.target)) {
    open.value = false
  }
}

function toggle() {
  open.value = !open.value
}

function seeAll() {
  open.value = false
  router.push({ name: 'notifications' })
}
</script>

<template>
  <div ref="bellWrapRef" class="bell-wrap">
    <button
      class="vk-icon-btn bell-btn"
      :class="{ active: open, 'has-unread': notificationsStore.hasUnread }"
      :aria-label="`Notifications${notificationsStore.unreadCount ? ` (${notificationsStore.unreadCount} unread)` : ''}`"
      :title="`Notifications${notificationsStore.unreadCount ? ` · ${notificationsStore.unreadCount} unread` : ''}`"
      @click.stop="toggle"
    >
      <IconBell aria-hidden="true" />

      <!-- Unread badge -->
      <span
        v-if="notificationsStore.hasUnread"
        class="badge"
        aria-hidden="true"
      >
        {{ notificationsStore.unreadCount > 9 ? '9+' : notificationsStore.unreadCount }}
      </span>
    </button>

    <!-- Dropdown -->
    <Transition name="bell-drop">
      <div
        v-if="open"
        class="dropdown"
        role="region"
        aria-label="Notifications preview"
        @click.stop
      >
        <div class="dropdown-header">
          <span class="dropdown-title">Notifications</span>
          <button
            v-if="notificationsStore.hasUnread"
            class="mark-all"
            @click="notificationsStore.markAllRead()"
          >
            Mark all read
          </button>
        </div>

        <div v-if="notificationsStore.loading" class="empty">Loading…</div>

        <div
          v-else-if="!notificationsStore.items.length"
          class="empty"
        >
          No notifications yet.
        </div>

        <ul v-else class="preview-list">
          <NotificationItem
            v-for="item in notificationsStore.items.slice(0, 5)"
            :key="item.id"
            :notification="item"
            :action-loading="notificationsStore.actionLoading"
          />
        </ul>

        <button class="see-all" @click="seeAll">
          See all notifications
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.bell-wrap {
  position: relative;
}

.vk-icon-btn {
  flex: 0 0 auto;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: 1px solid var(--hairline);
  border-radius: 6px;
  color: var(--ink-70);
  cursor: pointer;
  padding: 0;
  transition: all 120ms ease;
  position: relative;
}
.vk-icon-btn:hover,
.vk-icon-btn.active {
  color: #1a1408;
  background: var(--amber);
  border-color: var(--amber);
}

.badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 14px;
  height: 14px;
  padding: 0 3px;
  background: #d46a6a;
  color: #fff;
  border-radius: 999px;
  font-size: 9px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  border: 1px solid var(--bg-1);
}

/* Dropdown panel */
.dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 380px;
  max-width: calc(100vw - 32px);
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.6);
  z-index: 8000;
  overflow: hidden;
}

.dropdown-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px 8px;
  border-bottom: 1px solid var(--hairline);
}

.dropdown-title {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-50);
}

.mark-all {
  background: none;
  border: none;
  color: var(--amber);
  font-size: 11px;
  cursor: pointer;
  padding: 0;
}
.mark-all:hover { text-decoration: underline; }

.preview-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 360px;
  overflow-y: auto;
}

.empty {
  padding: 20px 16px;
  text-align: center;
  color: var(--ink-50);
  font-size: 13px;
}

.see-all {
  display: block;
  width: 100%;
  padding: 10px 14px;
  background: transparent;
  border: none;
  border-top: 1px solid var(--hairline);
  color: var(--amber);
  font-size: 12px;
  font-weight: 500;
  text-align: center;
  cursor: pointer;
  transition: background 0.1s ease;
}
.see-all:hover { background: var(--bg-3); }

/* Dropdown enter/leave transition */
.bell-drop-enter-active,
.bell-drop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.bell-drop-enter-from,
.bell-drop-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
