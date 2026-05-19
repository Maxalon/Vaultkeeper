import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useNotificationsStore } from '../notifications.js'

vi.mock('../../lib/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import api from '../../lib/api'
import { notificationsFixture } from '../../__mocks__/friends/index.js'

// Deep-clone so store mutations don't bleed between tests via shared fixture refs.
function cloneFixture() {
  return JSON.parse(JSON.stringify(notificationsFixture))
}

function mockFetch() {
  api.get.mockResolvedValueOnce({ data: cloneFixture() })
}

beforeEach(() => {
  setActivePinia(createPinia())
  // resetAllMocks clears calls AND flushes any leftover mockResolvedValueOnce queue.
  vi.resetAllMocks()
})

describe('notifications store', () => {
  it('starts with empty state', () => {
    const store = useNotificationsStore()
    expect(store.items).toEqual([])
    expect(store.unreadCount).toBe(0)
    expect(store.loading).toBe(false)
    expect(store.actionLoading).toBeNull()
    expect(store.error).toBeNull()
  })

  it('hasUnread getter is false when unreadCount is 0', () => {
    const store = useNotificationsStore()
    expect(store.hasUnread).toBe(false)
  })

  it('hasUnread getter is true when unreadCount > 0', async () => {
    mockFetch()
    const store = useNotificationsStore()
    await store.fetchNotifications()
    expect(store.hasUnread).toBe(true)
  })

  it('byId getter returns the matching notification', async () => {
    mockFetch()
    const store = useNotificationsStore()
    await store.fetchNotifications()
    expect(store.byId(1)).toEqual(notificationsFixture.data[0])
  })

  it('byId getter returns null for an unknown id', async () => {
    mockFetch()
    const store = useNotificationsStore()
    await store.fetchNotifications()
    expect(store.byId(9999)).toBeNull()
  })

  describe('fetchNotifications()', () => {
    it('flips loading flag while in flight', async () => {
      let resolve
      api.get.mockReturnValueOnce(new Promise((r) => { resolve = r }))
      const store = useNotificationsStore()
      const p = store.fetchNotifications()
      expect(store.loading).toBe(true)
      resolve({ data: cloneFixture() })
      await p
      expect(store.loading).toBe(false)
    })

    it('populates items and unreadCount from response', async () => {
      mockFetch()
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(store.items).toHaveLength(notificationsFixture.data.length)
      expect(store.unreadCount).toBe(notificationsFixture.meta.unread_count)
    })

    it('calls GET /notifications', async () => {
      mockFetch()
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(api.get).toHaveBeenCalledWith('/notifications', { params: { page: 1 } })
    })

    it('passes unread=1 param when unreadOnly is true', async () => {
      mockFetch()
      const store = useNotificationsStore()
      await store.fetchNotifications({ unreadOnly: true })
      expect(api.get).toHaveBeenCalledWith('/notifications', { params: { page: 1, unread: 1 } })
    })

    it('sets error on failure', async () => {
      api.get.mockRejectedValueOnce({ response: { data: { message: 'Server error' } } })
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(store.error).toBe('Server error')
      expect(store.items).toEqual([])
    })

    it('uses fallback error message when response has no message', async () => {
      api.get.mockRejectedValueOnce(new Error('network'))
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(store.error).toBe('Failed to load notifications')
    })
  })

  describe('markRead()', () => {
    it('POSTs to the read endpoint', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await store.markRead(1)
      expect(api.post).toHaveBeenCalledWith('/notifications/1/read')
    })

    it('sets read_at on the item optimistically', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(store.byId(1).read_at).toBeNull()
      await store.markRead(1)
      expect(store.byId(1).read_at).not.toBeNull()
    })

    it('decrements unreadCount when marking an unread item', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      const before = store.unreadCount
      await store.markRead(1)
      expect(store.unreadCount).toBe(before - 1)
    })

    it('does not double-decrement when marking an already-read item', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      // id 3 is already read (read_at is set in the fixture)
      const before = store.unreadCount
      await store.markRead(3)
      expect(store.unreadCount).toBe(before)
    })

    it('swallows errors silently (optimistic update already applied)', async () => {
      mockFetch()
      api.post.mockRejectedValueOnce(new Error('net'))
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await expect(store.markRead(1)).resolves.toBeUndefined()
    })
  })

  describe('markAllRead()', () => {
    it('POSTs to /notifications/read-all', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await store.markAllRead()
      expect(api.post).toHaveBeenCalledWith('/notifications/read-all')
    })

    it('sets read_at on all unread items', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await store.markAllRead()
      for (const item of store.items) {
        expect(item.read_at).not.toBeNull()
      }
    })

    it('resets unreadCount to 0', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await store.markAllRead()
      expect(store.unreadCount).toBe(0)
    })
  })

  describe('runAction()', () => {
    it('POSTs to the action gateway endpoint', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({ data: { ok: true } })
      api.post.mockResolvedValueOnce({}) // markRead
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await store.runAction(1, 'accept')
      expect(api.post).toHaveBeenCalledWith('/notifications/1/actions/accept')
    })

    it('sets actionLoading to the notification id while in flight', async () => {
      mockFetch()
      let resolveAction
      api.post.mockReturnValueOnce(new Promise((r) => { resolveAction = r }))
      const store = useNotificationsStore()
      await store.fetchNotifications()
      const p = store.runAction(1, 'accept')
      expect(store.actionLoading).toBe(1)
      resolveAction({ data: { ok: true } })
      api.post.mockResolvedValueOnce({}) // markRead
      await p
      expect(store.actionLoading).toBeNull()
    })

    it('calls markRead after a successful action', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({ data: { ok: true } }) // action
      api.post.mockResolvedValueOnce({})                      // markRead
      const store = useNotificationsStore()
      await store.fetchNotifications()
      expect(store.byId(1).read_at).toBeNull()
      await store.runAction(1, 'accept')
      expect(store.byId(1).read_at).not.toBeNull()
    })

    it('returns the gateway response body', async () => {
      mockFetch()
      api.post.mockResolvedValueOnce({ data: { ok: true } })
      api.post.mockResolvedValueOnce({})
      const store = useNotificationsStore()
      await store.fetchNotifications()
      const result = await store.runAction(1, 'accept')
      expect(result).toEqual({ ok: true })
    })

    it('clears actionLoading even when the action throws', async () => {
      mockFetch()
      api.post.mockRejectedValueOnce(new Error('net'))
      const store = useNotificationsStore()
      await store.fetchNotifications()
      await expect(store.runAction(1, 'accept')).rejects.toThrow()
      expect(store.actionLoading).toBeNull()
    })
  })

  describe('addOptimistic() / removeOptimistic()', () => {
    it('addOptimistic() prepends an unread notification and increments unreadCount', () => {
      const store = useNotificationsStore()
      const n = { id: 99, type: 'test', payload: {}, actions: [], read_at: null, created_at: new Date().toISOString() }
      store.addOptimistic(n)
      expect(store.items[0]).toStrictEqual(n)
      expect(store.unreadCount).toBe(1)
    })

    it('addOptimistic() does not increment unreadCount for already-read notifications', () => {
      const store = useNotificationsStore()
      const n = { id: 99, type: 'test', payload: {}, actions: [], read_at: new Date().toISOString(), created_at: new Date().toISOString() }
      store.addOptimistic(n)
      expect(store.unreadCount).toBe(0)
    })

    it('removeOptimistic() removes the notification by id', () => {
      const store = useNotificationsStore()
      const n = { id: 99, type: 'test', payload: {}, actions: [], read_at: null, created_at: new Date().toISOString() }
      store.addOptimistic(n)
      store.removeOptimistic(99)
      expect(store.items.find((i) => i.id === 99)).toBeUndefined()
    })

    it('removeOptimistic() decrements unreadCount when removing an unread notification', () => {
      const store = useNotificationsStore()
      const n = { id: 99, type: 'test', payload: {}, actions: [], read_at: null, created_at: new Date().toISOString() }
      store.addOptimistic(n)
      expect(store.unreadCount).toBe(1)
      store.removeOptimistic(99)
      expect(store.unreadCount).toBe(0)
    })

    it('removeOptimistic() is a no-op for an unknown id', () => {
      const store = useNotificationsStore()
      expect(() => store.removeOptimistic(999)).not.toThrow()
    })
  })
})
