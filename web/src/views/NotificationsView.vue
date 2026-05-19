<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '../stores/notifications'
import { useToast } from '../composables/useToast'
import NotificationItem from '../components/NotificationItem.vue'
import VaultMark from '../components/VaultMark.vue'

const router = useRouter()
const notificationsStore = useNotificationsStore()
const toast = useToast()

onMounted(() => {
  notificationsStore.fetchNotifications()
})

function goBack() {
  const target = window.history.state?.returnTo
  if (target && typeof target === 'string') router.push(target)
  else router.push('/collection')
}

async function markAllRead() {
  try {
    await notificationsStore.markAllRead()
    toast.success('All notifications marked as read.')
  } catch (e) {
    toast.error('Failed to mark all as read.')
  }
}

// ── Pull-to-refresh ───────────────────────────────────────────────────────
const PULL_THRESHOLD = 72

const pullY = ref(0)
const refreshing = ref(false)

let touchStartY = 0
let trackingPull = false

function onTouchStart(e) {
  if (window.scrollY === 0) {
    touchStartY = e.touches[0].clientY
    trackingPull = true
  }
}

function onTouchMove(e) {
  if (!trackingPull) return
  const delta = e.touches[0].clientY - touchStartY
  if (delta > 0) {
    // Dampen so the indicator trails the finger rather than matching it 1:1
    pullY.value = Math.min(delta * 0.45, PULL_THRESHOLD + 24)
  } else {
    pullY.value = 0
  }
}

async function onTouchEnd() {
  if (pullY.value >= PULL_THRESHOLD && !refreshing.value) {
    refreshing.value = true
    pullY.value = PULL_THRESHOLD
    await notificationsStore.fetchNotifications()
    refreshing.value = false
  }
  pullY.value = 0
  trackingPull = false
}
</script>

<template>
  <main
    class="notifications-page"
    @touchstart.passive="onTouchStart"
    @touchmove.passive="onTouchMove"
    @touchend.passive="onTouchEnd"
  >
    <!-- Pull-to-refresh indicator -->
    <div
      v-if="pullY > 0 || refreshing"
      class="pull-indicator"
      :class="{ releasing: pullY >= PULL_THRESHOLD, refreshing }"
      :style="{ transform: `translateX(-50%) translateY(${Math.min(pullY, PULL_THRESHOLD)}px)` }"
      aria-live="polite"
    >
      {{ refreshing ? 'Refreshing…' : pullY >= PULL_THRESHOLD ? 'Release to refresh' : 'Pull to refresh' }}
    </div>

    <header class="notifications-header">
      <VaultMark />
      <button class="back" @click="goBack">← Back</button>
    </header>

    <section class="notifications-content">
      <div class="page-title-row">
        <h1 class="title">Notifications</h1>
        <button
          v-if="notificationsStore.hasUnread"
          class="mark-all-btn"
          @click="markAllRead"
        >
          Mark all read
        </button>
      </div>

      <div v-if="notificationsStore.loading" class="empty">Loading…</div>

      <div
        v-else-if="!notificationsStore.items.length"
        class="empty"
      >
        You're all caught up — no notifications.
      </div>

      <ul v-else class="notification-list">
        <NotificationItem
          v-for="notification in notificationsStore.items"
          :key="notification.id"
          :notification="notification"
          :action-loading="notificationsStore.actionLoading"
        />
      </ul>
    </section>
  </main>
</template>

<style scoped>
.notifications-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  padding: 32px 48px 64px;
}

.notifications-header {
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

.notifications-content {
  max-width: 720px;
  margin: 0 auto;
}

.page-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.title {
  font-family: var(--font-display), serif;
  font-size: 36px;
  font-weight: 400;
  letter-spacing: -0.02em;
  color: var(--amber);
  margin: 0;
}

.mark-all-btn {
  height: 30px;
  padding: 0 14px;
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  border-radius: var(--radius-sm);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.12s ease;
}
.mark-all-btn:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-1);
}

.empty {
  padding: 48px 20px;
  text-align: center;
  color: var(--ink-50);
  font-size: 14px;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
}

.notification-list {
  list-style: none;
  margin: 0;
  padding: 0;
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.pull-indicator {
  position: fixed;
  top: -40px;
  left: 50%;
  height: 32px;
  padding: 0 16px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: 999px;
  display: flex;
  align-items: center;
  font-size: 12px;
  color: var(--ink-50);
  white-space: nowrap;
  pointer-events: none;
  transition: color 0.1s ease;
  z-index: 100;
}
.pull-indicator.releasing { color: var(--amber); }
.pull-indicator.refreshing { color: var(--ink-70); }
</style>
