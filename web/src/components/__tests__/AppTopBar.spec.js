import { describe, it, expect, beforeEach, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { useFriendsStore } from '../../stores/friends'

vi.mock('../../lib/api', () => ({
  default: { get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ fullPath: '/' }),
  useRouter: () => ({ push: vi.fn() }),
}))
vi.mock('../../stores/collection', () => ({
  useCollectionStore: () => ({
    entries: [],
    filters: { search: '', sort: 'name' },
    selecting: false,
    toggleSelecting: vi.fn(),
    fetchEntries: vi.fn(),
  }),
  parseSearch: () => ({ nameQuery: '', chips: {}, sort: 'name' }),
  serializeQuery: () => '',
}))
vi.mock('../../assets/icons/friends.svg', () => ({ default: { template: '<svg />' } }))
vi.mock('../../assets/icons/settings.svg', () => ({ default: { template: '<svg />' } }))

beforeEach(() => {
  setActivePinia(createPinia())
})

async function mountBar() {
  const { default: AppTopBar } = await import('../AppTopBar.vue')
  return shallowMount(AppTopBar, { props: { mode: 'collection' } })
}

describe('AppTopBar friends badge', () => {
  it('shows no badge when there are no pending incoming requests', async () => {
    const friends = useFriendsStore()
    friends.incomingRequests = []

    const wrapper = await mountBar()
    expect(wrapper.find('.friends-badge').exists()).toBe(false)
  })

  it('shows the count when pendingIncomingCount is ≤ 9', async () => {
    const friends = useFriendsStore()
    friends.incomingRequests = Array.from({ length: 5 }, (_, i) => ({ id: i + 1 }))

    const wrapper = await mountBar()
    const badge = wrapper.find('.friends-badge')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('5')
  })

  it('caps the badge label at "9+" when pendingIncomingCount exceeds 9', async () => {
    const friends = useFriendsStore()
    friends.incomingRequests = Array.from({ length: 12 }, (_, i) => ({ id: i + 1 }))

    const wrapper = await mountBar()
    const badge = wrapper.find('.friends-badge')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('9+')
  })
})
