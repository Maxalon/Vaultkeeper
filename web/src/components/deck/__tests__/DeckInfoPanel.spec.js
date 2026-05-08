import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import DeckInfoPanel from '../DeckInfoPanel.vue'
import { useDeckStore } from '../../../stores/deck'

vi.mock('../../../stores/prices', () => ({
  usePricesStore: () => ({
    deckTotalsByDeckId: {},
    fetchStatus: () => Promise.resolve(),
    fetchDeckTotals: () => Promise.resolve(),
    scheduleRefresh: () => {},
  }),
}))
vi.mock('../../../stores/tabs', () => ({
  useTabsStore: () => ({ openTab: () => {} }),
}))
vi.mock('../../../composables/useToast', () => ({
  useToast: () => ({ success: () => {}, error: () => {}, withActions: () => {} }),
}))
vi.mock('../../../composables/useConfirm', () => ({ confirm: () => Promise.resolve(false) }))

beforeEach(() => {
  setActivePinia(createPinia())
})

const COMMANDER_SID = '56c1227e-bea7-47cb-bbec-389a3d585af5'
const OTHER_SID     = 'e8654e38-4230-4094-b815-778bfb5d06f2'

function setupDeck() {
  const store = useDeckStore()
  store.deck = {
    id: 397,
    name: 'Test',
    format: 'commander',
    color_identity: '',
    commander1: {
      scryfall_id: COMMANDER_SID,
      name: 'Test Commander',
      image_normal: null,
      image_small: null,
      color_identity: [],
      commander_game_changer: false,
    },
    commander2: null,
    companion: null,
  }
  store.entries = [
    { id: 34135, deck_id: 397, scryfall_id: OTHER_SID, quantity: 1, zone: 'main',
      category: 'creature', is_commander: false, is_signature_spell: false,
      physical_copy_id: null, wanted: 'main' },
    { id: 34136, deck_id: 397, scryfall_id: COMMANDER_SID, quantity: 1, zone: 'main',
      category: 'draw', is_commander: true, is_signature_spell: false,
      physical_copy_id: null, wanted: null },
  ]
  return store
}

describe('DeckInfoPanel commander click', () => {
  it('opens detail sidebar by setting activeEntryId on commander click', async () => {
    const store = setupDeck()
    const wrapper = mount(DeckInfoPanel, {
      global: { stubs: { ManaSymbol: true, ExportMenu: true, AssembleDeckModal: true } },
    })
    const portrait = wrapper.find('.commander-portrait')
    expect(portrait.exists()).toBe(true)
    await portrait.trigger('click')
    expect(store.activeEntryId).toBe(34136)
  })

  it('falls back to is_commander flag when scryfall_id mismatches', async () => {
    const store = setupDeck()
    store.deck.commander1 = { ...store.deck.commander1, scryfall_id: 'no-such-sid' }
    const wrapper = mount(DeckInfoPanel, {
      global: { stubs: { ManaSymbol: true, ExportMenu: true, AssembleDeckModal: true } },
    })
    await wrapper.find('.commander-portrait').trigger('click')
    // After the fix, this should find entry 34136 by is_commander flag.
    expect(store.activeEntryId).toBe(34136)
  })
})
