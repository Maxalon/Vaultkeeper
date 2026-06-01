import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import CatalogSearchModal from '../CatalogSearchModal.vue'

const mockGet = vi.hoisted(() => vi.fn())
const mockAddEntry = vi.hoisted(() => vi.fn())

vi.mock('../../lib/api', () => ({ default: { get: mockGet } }))
vi.mock('../../stores/deck', () => ({ useDeckStore: () => ({ addEntry: mockAddEntry }) }))
vi.mock('../../stores/prices', () => ({
  usePricesStore: () => ({ fetchStatus: vi.fn().mockResolvedValue({}) }),
}))

const CARD = {
  oracle_id: 'oracle-1',
  scryfall_id: 'scryfall-1',
  name: 'Lightning Bolt',
  mana_cost: '{R}',
  type_line: 'Instant',
  icon_svg_uri: null,
}

// Teleport stub renders slot content inline so wrapper.find() can reach it.
const STUBS = {
  Teleport: { template: '<slot />' },
  SyntaxSearch: {
    template: '<input class="stub-search" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue', 'help'],
  },
  ManaCost: true,
  CardDetailBody: true,
  PriceLine: true,
  PrintingPickerModal: true,
}

function searchResponse(overrides = {}) {
  return { data: { data: [CARD], total: 1, last_page: 1, warnings: [], ...overrides } }
}

async function triggerSearch(wrapper, query = 'lightning') {
  await wrapper.find('.stub-search').setValue(query)
  vi.advanceTimersByTime(300)
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers()
  mockGet.mockReset()
  mockAddEntry.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('CatalogSearchModal loadMore guard', () => {
  it('does not call API when page is already at lastPage', async () => {
    mockGet.mockResolvedValue(searchResponse({ last_page: 1 }))
    const wrapper = mount(CatalogSearchModal, {
      props: { open: true, deckId: 1 },
      global: { stubs: STUBS },
    })

    await triggerSearch(wrapper)
    expect(mockGet).toHaveBeenCalledTimes(1)

    // In happy-dom scrollHeight/scrollTop/clientHeight are all 0, so the
    // near-bottom condition (0 - 0 - 0 < 300) is always true — loadMore()
    // runs. page(1) >= lastPage(1) so the guard must return without another GET.
    await wrapper.find('.csm-results').trigger('scroll')
    await flushPromises()

    expect(mockGet).toHaveBeenCalledTimes(1)
  })

  it('does not issue a concurrent loadMore while one is already in-flight', async () => {
    let resolveLoadMore
    const hangingLoadMore = new Promise((resolve) => { resolveLoadMore = resolve })

    mockGet
      .mockResolvedValueOnce(searchResponse({ total: 80, last_page: 2 }))
      .mockReturnValueOnce(hangingLoadMore)

    const wrapper = mount(CatalogSearchModal, {
      props: { open: true, deckId: 1 },
      global: { stubs: STUBS },
    })

    await triggerSearch(wrapper)
    expect(mockGet).toHaveBeenCalledTimes(1)

    // First scroll: page(1) < lastPage(2), so loadMore fires and hangs.
    await wrapper.find('.csm-results').trigger('scroll')
    expect(mockGet).toHaveBeenCalledTimes(2)

    // Second scroll while loadingMore is still true: guard must block.
    await wrapper.find('.csm-results').trigger('scroll')
    expect(mockGet).toHaveBeenCalledTimes(2)

    resolveLoadMore({ data: { data: [], total: 80, last_page: 2, warnings: [] } })
    await flushPromises()
  })
})

describe('CatalogSearchModal addToDeck guard', () => {
  it('does not call addEntry a second time while the first is still in-flight', async () => {
    let resolveAdd
    const hangingAdd = new Promise((resolve) => { resolveAdd = resolve })

    mockGet.mockImplementation((url) => {
      if (url.includes('printings')) return Promise.resolve({ data: { data: [] } })
      return Promise.resolve(searchResponse())
    })
    mockAddEntry.mockReturnValue(hangingAdd)

    const wrapper = mount(CatalogSearchModal, {
      props: { open: true, deckId: 1 },
      global: { stubs: STUBS },
    })

    await triggerSearch(wrapper)

    // Click a card row to select it; detail pane + add buttons appear.
    await wrapper.find('.csm-row').trigger('click')
    await flushPromises()

    const addBtn = wrapper.findAll('.csm-add-buttons button').at(0)
    expect(addBtn.exists()).toBe(true)

    // First click: addEntry fires, adding becomes true.
    await addBtn.trigger('click')
    expect(mockAddEntry).toHaveBeenCalledTimes(1)

    // Second click while adding is still true: guard must block.
    await addBtn.trigger('click')
    expect(mockAddEntry).toHaveBeenCalledTimes(1)

    resolveAdd({})
    await flushPromises()
  })
})
