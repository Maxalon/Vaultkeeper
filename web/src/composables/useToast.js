import { reactive } from 'vue'

const state = reactive({
  toasts: [],
})

let seq = 0
const DEFAULT_TTL = 4000
/**
 * Toasts that ship with action buttons get a longer default lifetime
 * since the user needs time to read + decide. Click-actions also abort
 * the timer (handled in the host).
 */
const ACTION_TTL = 8000

/**
 * B7 — Dual-fire support.
 *
 * When `opts.persist` is true the toast is *also* written to the
 * notifications store as an optimistic in-memory notification. This gives
 * the user a "recent activity log" in the notification bell that survives
 * the toast disappearing.
 *
 * The notifications store is lazy-imported (via dynamic import) to avoid
 * a circular dep at module load time (useToast ↔ notifications store).
 * The same lazy-import pattern as the router/api.js 401 interceptor.
 *
 * Opts shape:
 *   actions  - Toast actions array (existing): [{ label, run, kind? }]
 *   persist  - boolean (NEW): if true, also write to notifications store
 *   notificationType - string override for the notification type field;
 *                      defaults to 'toast.<kind>'
 *   notificationPayload - extra payload fields to attach to the notification
 */
async function persistToNotifications(id, kind, message, opts) {
  try {
    // Lazy import avoids circular dep — same as the router lazy-import in api.js
    const { useNotificationsStore } = await import('../stores/notifications')
    const notificationsStore = useNotificationsStore()

    // Map toast actions → declarative notification actions (available: true
    // at creation time; staleness is only server-checkable after server persist,
    // which optimistic-only notifications don't have — so we leave actions
    // with available: true and let the UI handle post-click refresh)
    const notifActions = (opts.actions || []).map((a) => ({
      key: a.key || a.label.toLowerCase().replace(/\s+/g, '_'),
      label: a.label,
      kind: a.kind || 'default',
      // Client-side optimistic actions: no server endpoint, run() is local
      endpoint: null,
      method: null,
      body: {},
      available: true,
      _localRun: a.run, // preserved for local action dispatch
    }))

    notificationsStore.addOptimistic({
      id: `toast-${id}`, // string id to avoid colliding with server numeric ids
      type: opts.notificationType || `toast.${kind}`,
      payload: {
        message,
        ...(opts.notificationPayload || {}),
      },
      actions: notifActions,
      read_at: null,
      created_at: new Date().toISOString(),
    })
  } catch (e) {
    // Non-fatal: toast still shows even if notification store write fails
    /* eslint-disable-next-line no-console */
    console.warn('useToast: failed to persist notification', e)
  }
}

function push(kind, message, ttl = DEFAULT_TTL, opts = {}) {
  const id = ++seq
  const toast = {
    id,
    kind,
    message,
    actions: opts.actions || null,
  }
  state.toasts.push(toast)
  if (ttl > 0) {
    setTimeout(() => dismiss(id), ttl)
  }

  // B7: dual-fire — also write to notifications store if persist: true
  if (opts.persist) {
    persistToNotifications(id, kind, message, opts)
  }

  return id
}

function dismiss(id) {
  const i = state.toasts.findIndex((t) => t.id === id)
  if (i !== -1) state.toasts.splice(i, 1)
}

/**
 * Run an action attached to a toast. Each action callback may be async;
 * we dismiss the toast immediately so the user gets feedback that their
 * click landed, then await the work.
 */
async function runAction(toastId, action) {
  dismiss(toastId)
  try {
    await action.run()
  } catch (e) {
    // Action errors fall through to whichever store/composable
    // initiated them (they typically fire their own error toast).
    /* eslint-disable-next-line no-console */
    console.warn('Toast action failed:', e)
  }
}

export function useToast() {
  return {
    toasts: state.toasts,
    success: (msg, ttl, opts = {}) => push('success', msg, ttl, opts),
    error:   (msg, ttl, opts = {}) => push('error',   msg, ttl, opts),
    info:    (msg, ttl, opts = {}) => push('info',    msg, ttl, opts),
    /**
     * Toast with action buttons. `actions` is an array of
     * `{ label, run, kind? }`; `kind` is 'primary' | 'default' for
     * styling. Clicking an action runs its callback and dismisses the
     * toast.
     *
     * Pass `persist: true` in the options object to also write to the
     * notifications store (B7 dual-fire). Existing callers that pass only
     * `{ kind, ttl }` continue to work unchanged.
     */
    withActions: (msg, actions, { kind = 'info', ttl = ACTION_TTL, persist = false, notificationType, notificationPayload } = {}) =>
      push(kind, msg, ttl, { actions, persist, notificationType, notificationPayload }),
    dismiss,
    runAction,
  }
}
