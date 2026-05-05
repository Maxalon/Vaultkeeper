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

  it('withActions attaches action buttons to the toast', () => {
    const t = useToast()
    const ran = []
    t.withActions('Heads up', [
      { label: 'Apply', kind: 'primary', run: () => ran.push('apply') },
      { label: 'Skip',  run: () => ran.push('skip') },
    ])
    expect(t.toasts).toHaveLength(1)
    expect(t.toasts[0].actions).toHaveLength(2)
    expect(t.toasts[0].actions[0].label).toBe('Apply')
  })

  it('runAction dismisses the toast and invokes the callback', async () => {
    vi.useRealTimers() // runAction is async
    const t = useToast()
    let called = false
    const action = { label: 'Go', run: () => { called = true } }
    const id = t.withActions('Heads up', [action])
    expect(t.toasts).toHaveLength(1)
    await t.runAction(id, action)
    expect(called).toBe(true)
    expect(t.toasts).toHaveLength(0)
  })
})
