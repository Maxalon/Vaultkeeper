import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { useToast } from '../useToast.js'

beforeEach(() => {
  vi.useFakeTimers()
  const t = useToast()
  // Reset the shared array
  while (t.toasts.length) t.toasts.pop()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useToast', () => {
  it('appends toasts of various kinds', () => {
    const t = useToast()
    t.success('ok')
    t.error('bad')
    t.info('hi')
    expect(t.toasts).toHaveLength(3)
    expect(t.toasts.map((x) => x.kind)).toEqual(['success', 'error', 'info'])
  })

  it('auto-dismisses after the ttl', () => {
    const t = useToast()
    t.success('ok', 1000)
    expect(t.toasts).toHaveLength(1)
    vi.advanceTimersByTime(1500)
    expect(t.toasts).toHaveLength(0)
  })

  it('manual dismiss removes immediately', () => {
    const t = useToast()
    const id = t.info('poke', 0)
    expect(t.toasts).toHaveLength(1)
    t.dismiss(id)
    expect(t.toasts).toHaveLength(0)
  })
})
