import { reactive } from 'vue'

const state = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  destructive: false,
})

let resolver = null

export function confirm(opts = {}) {
  state.title       = opts.title ?? ''
  state.message     = opts.message ?? ''
  state.confirmText = opts.confirmText ?? 'Confirm'
  state.cancelText  = opts.cancelText  ?? 'Cancel'
  state.destructive = !!opts.destructive
  state.open        = true
  return new Promise((resolve) => {
    resolver = resolve
  })
}

export function _resolveConfirm(result) {
  state.open = false
  if (resolver) {
    const r = resolver
    resolver = null
    r(result)
  }
}

export function useConfirmState() {
  return state
}
