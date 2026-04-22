import { reactive } from 'vue'

const state = reactive({
  toasts: [],
})

let seq = 0
const DEFAULT_TTL = 4000

function push(kind, message, ttl = DEFAULT_TTL) {
  const id = ++seq
  state.toasts.push({ id, kind, message })
  if (ttl > 0) {
    setTimeout(() => dismiss(id), ttl)
  }
  return id
}

function dismiss(id) {
  const i = state.toasts.findIndex((t) => t.id === id)
  if (i !== -1) state.toasts.splice(i, 1)
}

export function useToast() {
  return {
    toasts: state.toasts,
    success: (msg, ttl) => push('success', msg, ttl),
    error:   (msg, ttl) => push('error',   msg, ttl),
    info:    (msg, ttl) => push('info',    msg, ttl),
    dismiss,
  }
}
