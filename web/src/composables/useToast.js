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
    success: (msg, ttl) => push('success', msg, ttl),
    error:   (msg, ttl) => push('error',   msg, ttl),
    info:    (msg, ttl) => push('info',    msg, ttl),
    /**
     * Toast with action buttons. `actions` is an array of
     * `{ label, run, kind? }`; `kind` is 'primary' | 'default' for
     * styling. Clicking an action runs its callback and dismisses the
     * toast.
     */
    withActions: (msg, actions, { kind = 'info', ttl = ACTION_TTL } = {}) =>
      push(kind, msg, ttl, { actions }),
    dismiss,
    runAction,
  }
}
