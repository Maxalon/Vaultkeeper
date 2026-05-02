import { reactive } from 'vue'

const state = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  destructive: false,
  // Optional checkbox shown above the action buttons. When set, callers receive
  // an object { confirmed, checkboxChecked } instead of a bare boolean.
  checkbox: null, // { label, dangerous? } | null
  checkboxChecked: false,
})

let resolver = null
let hasCheckbox = false

export function confirm(opts = {}) {
  state.title       = opts.title ?? ''
  state.message     = opts.message ?? ''
  state.confirmText = opts.confirmText ?? 'Confirm'
  state.cancelText  = opts.cancelText  ?? 'Cancel'
  state.destructive = !!opts.destructive
  state.checkbox    = opts.checkbox ?? null
  state.checkboxChecked = false
  state.open        = true
  hasCheckbox = !!opts.checkbox
  return new Promise((resolve) => {
    resolver = resolve
  })
}

export function _resolveConfirm(result) {
  const checkboxChecked = !!state.checkboxChecked
  const wasCheckbox = hasCheckbox
  state.open = false
  state.checkbox = null
  state.checkboxChecked = false
  hasCheckbox = false
  if (resolver) {
    const r = resolver
    resolver = null
    if (wasCheckbox) {
      r({ confirmed: !!result, checkboxChecked })
    } else {
      r(result)
    }
  }
}

export function useConfirmState() {
  return state
}
