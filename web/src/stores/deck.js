import { defineStore } from 'pinia'
import api from '../lib/api'
import { useToast } from '../composables/useToast'
import { useCollectionStore } from './collection'
import {
  parseSearch as parseSearchGeneric,
  serializeQuery as serializeQueryGeneric,
} from '../lib/searchQuery'

/**
 * Illegality classification — card-level illegalities glow on the card;
 * deck-level glow on the deck name. See DB-3 plan decision 15.
 */
const DECK_LEVEL_ILLEGALITY_TYPES = new Set([
  'deck_size',
  'too_many_cards',
  'invalid_commander',
  'invalid_partner',
])

export const DECK_SCHEMA = {
  chipKeys: ['c', 't', 'r'],
  directiveKeys: [],
  aliases: {},
}

/** Defaults for the display-control row — applied when a chip is cleared. */
const DECK_DIRECTIVE_DEFAULTS = {
  group: 'categories',
  sort: 'name',
  display: 'strips',
}

/** Directive key → canonical view field. */
const DIRECTIVE_TO_VIEW = {
  group: 'groupBy',
  sort: 'sort',
  display: 'displayMode',
}

export const useDeckStore = defineStore('deck', {
  state: () => ({
    deck: null,
    entries: [],
    /** Map of scryfall_id → CollectionEntry[] for the physical-copy dropdown */
    ownedCopiesByScryfallId: {},
    illegalities: [],
    loading: false,
    /** Set of entry ids currently mid-patch — for spinners on in-flight rows */
    saving: new Set(),
    activeEntryId: null,
    /**
     * View state survives undock/redock. Persisted per-deck in localStorage
     * under `vaultkeeper_deck_view_{deckId}` by DeckFilterBar.
     */
    view: {
      search: '',
      groupBy: 'categories',
      sort: 'name',
      displayMode: 'strips',
    },
    /** Undock flags for side/maybe sections. */
    sideUndocked: false,
    maybeUndocked: false,
    /** Id of the deck entry currently being dragged (null when nothing is).
     *  Drives the floating "drop to remove" bin in DeckTabContent. */
    dragEntryId: null,
  }),

  getters: {
    entriesByZone: (state) => (zone) =>
      state.entries.filter((e) => e.zone === zone),

    /**
     * Same as `entriesByZone`, but coalesces partial-exclude split
     * pairs (locked decision 3.3) into a single display row. The
     * backend leaves the two raw rows in place so individual edits
     * still target the right one — this view is purely cosmetic.
     *
     * Each merged row carries:
     *   - the bound entry's id / scryfall_card / category / etc.
     *     (commands operating on row.id naturally hit the bound row;
     *     +1 / catalog-drag route to the wanted sibling explicitly)
     *   - quantity = bound + wanted (the visual total)
     *   - owned_quantity / wanted_quantity for the badge
     *   - _split = true, _wantedEntry, and _boundEntry pointers so
     *     edit handlers can address either side directly.
     *
     * Purely-bound rows (no wanted sibling yet) get _canSplit = true
     * so `+1` knows to mint a wanted sibling via /decks/{id}/wanted
     * instead of bumping the bound row's CE-backed quantity.
     *
     * Commanders / signature spells are never merged — they always
     * have qty=1 and partial-exclude is rejected for them server-side.
     */
    mergedEntriesByZone: (state) => (zone) => {
      const inZone = state.entries.filter((e) => e.zone === zone)
      // Group by (scryfall_id, zone) — the only legitimate pair shape
      // is one bound + one wanted row.
      const groups = new Map()
      for (const e of inZone) {
        if (e.is_commander || e.is_signature_spell) continue
        const key = e.scryfall_id
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(e)
      }

      const merged = []
      const consumedIds = new Set()
      const annotated = new Map()
      for (const [, rows] of groups) {
        if (rows.length === 1) {
          const only = rows[0]
          if (only.physical_copy_id != null && only.wanted == null) {
            annotated.set(only.id, { ...only, _canSplit: true })
          }
          continue
        }
        const bound  = rows.find((r) => r.physical_copy_id !== null && r.physical_copy_id !== undefined)
        const wanted = rows.find((r) => r.wanted !== null && r.wanted !== undefined && (r.physical_copy_id === null || r.physical_copy_id === undefined))
        if (!bound || !wanted) continue
        const total = (bound.quantity || 0) + (wanted.quantity || 0)
        merged.push({
          ...bound,
          quantity: total,
          owned_quantity: bound.quantity || 0,
          wanted_quantity: wanted.quantity || 0,
          _split: true,
          _wantedEntry: wanted,
          _boundEntry: bound,
        })
        consumedIds.add(bound.id)
        consumedIds.add(wanted.id)
      }

      // Stitch unmerged entries (commanders/sigs included, plus any
      // ungrouped row) back in. Purely-bound singletons are returned
      // with _canSplit=true so +1 can route to the new endpoint.
      const out = []
      for (const e of inZone) {
        if (consumedIds.has(e.id)) continue
        out.push(annotated.get(e.id) || e)
      }
      return out.concat(merged)
    },

    commanderEntries: (state) =>
      state.entries.filter((e) => e.is_commander),

    signatureSpellEntries: (state) =>
      state.entries.filter((e) => e.is_signature_spell),

    companionEntry: (state) => {
      if (!state.deck?.companion_scryfall_id) return null
      return state.entries.find(
        (e) => e.scryfall_id === state.deck.companion_scryfall_id,
      ) || null
    },

    categoriesInDeck: (state) => {
      const set = new Set()
      for (const e of state.entries) {
        if (e.zone === 'main' && e.category) set.add(e.category)
      }
      return [...set].sort()
    },

    activeIllegalities: (state) =>
      state.illegalities.filter((i) => !i.ignored),

    /**
     * Map: scryfall_id → array of illegalities that should shine that card.
     * Deck-level illegalities are excluded — they glow on the deck name.
     */
    cardLevelIllegalitiesByScryfallId: (state) => {
      const map = {}
      for (const ill of state.illegalities) {
        if (ill.ignored) continue
        if (DECK_LEVEL_ILLEGALITY_TYPES.has(ill.type)) continue
        const id = ill.scryfall_id_1
        if (!id) continue
        if (!map[id]) map[id] = []
        map[id].push(ill)
      }
      return map
    },

    deckLevelIllegalities: (state) =>
      state.illegalities.filter(
        (i) => !i.ignored && DECK_LEVEL_ILLEGALITY_TYPES.has(i.type),
      ),

    hasDeckLevelIllegality() {
      return this.deckLevelIllegalities.length > 0
    },

    parsedView(state) {
      return parseSearchGeneric(state.view.search || '', DECK_SCHEMA)
    },
  },

  actions: {
    async loadDeck(id) {
      this.loading = true
      try {
        const { data } = await api.get(`/decks/${id}`)
        this.deck = data
      } finally {
        this.loading = false
      }
    },

    async loadEntries(id) {
      const { data } = await api.get(`/decks/${id}/entries`, {
        params: { sort: 'name' },
      })
      this.entries = data
    },

    async loadIllegalities(id) {
      const { data } = await api.get(`/decks/${id}/illegalities`)
      this.illegalities = data
    },

    async loadOwnedCopies(scryfallId) {
      if (!scryfallId) return
      const { data } = await api.get('/collection/copies', {
        params: { scryfall_id: scryfallId },
      })
      this.ownedCopiesByScryfallId = {
        ...this.ownedCopiesByScryfallId,
        [scryfallId]: data,
      }
      return data
    },

    async addEntry(deckId, payload) {
      const toast = useToast()
      try {
        const { data } = await api.post(`/decks/${deckId}/entries`, payload)
        this.entries.push(data)
        await Promise.all([
          this.loadIllegalities(deckId),
          useCollectionStore().fetchDecks(),
        ])
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to add card')
        throw e
      }
    },

    /**
     * "+1 want one more". The single endpoint behind the sidebar +1
     * button (when the active entry is bound or already wanted) and
     * the catalog-drag drop. Backend creates a fresh wanted-only
     * deck_entry or bumps an existing wanted sibling — either way,
     * a bound CE-backed quantity is never touched (that requires the
     * explicit inline-picker "I bought it" path).
     */
    async growWanted(deckId, scryfallId, zone, opts = {}) {
      const toast = useToast()
      const body = { scryfall_id: scryfallId, zone }
      if (opts.delta != null)    body.delta    = opts.delta
      if (opts.category != null) body.category = opts.category
      try {
        const { data } = await api.post(`/decks/${deckId}/wanted`, body)
        // Replace existing entry if the bumped sibling is already in
        // local state, else append the fresh row.
        const idx = this.entries.findIndex((e) => e.id === data.id)
        if (idx === -1) this.entries.push(data)
        else this.entries[idx] = { ...this.entries[idx], ...data }
        await Promise.all([
          this.loadIllegalities(deckId),
          useCollectionStore().fetchDecks(),
        ])
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to add card')
        throw e
      }
    },

    async updateEntry(deckId, entryId, patch) {
      const toast = useToast()
      const idx = this.entries.findIndex((e) => e.id === entryId)
      if (idx === -1) return
      const prev = { ...this.entries[idx] }
      this.entries[idx] = { ...prev, ...patch }
      this.saving.add(entryId)
      // Quantity / zone edits change the mainboard size shown in the sidebar.
      const countChanged = 'quantity' in patch || 'zone' in patch
      try {
        const { data } = await api.patch(
          `/decks/${deckId}/entries/${entryId}`,
          patch,
        )
        // Reconcile with server-authoritative response (keep derived fields)
        this.entries[idx] = { ...this.entries[idx], ...data }
        await Promise.all([
          this.loadIllegalities(deckId),
          countChanged ? useCollectionStore().fetchDecks() : null,
        ])
        return data
      } catch (e) {
        this.entries[idx] = prev
        toast.error(e.response?.data?.message || 'Update failed')
        throw e
      } finally {
        this.saving.delete(entryId)
      }
    },

    async moveEntryZone(deckId, entryId, zone) {
      return this.updateEntry(deckId, entryId, { zone })
    },

    async removeEntry(deckId, entryId, opts = {}) {
      const toast = useToast()
      const idx = this.entries.findIndex((e) => e.id === entryId)
      if (idx === -1) return
      const removed = this.entries.splice(idx, 1)[0]
      if (this.activeEntryId === entryId) this.activeEntryId = null
      // Whether the default-path delete will queue a CE for review.
      // (Discard skips the queue; a row with no bound copy has nothing
      // to queue either.)
      const willQueueForReview = !opts.discard && removed?.physical_copy_id != null
      const cardName = removed?.scryfall_card?.name || 'Card'
      try {
        const url = opts.discard
          ? `/decks/${deckId}/entries/${entryId}?discard=true`
          : `/decks/${deckId}/entries/${entryId}`
        await api.delete(url)
        await Promise.all([
          this.loadIllegalities(deckId),
          useCollectionStore().fetchDecks(),
          // Removing an entry might queue its copy for review — refresh
          // the sidebar summary so the badge is in sync. Discard doesn't
          // queue, so this is a no-op there, but it's cheap.
          useCollectionStore().fetchGroups(),
        ])
        if (willQueueForReview) {
          // Default path queued the freed copy for review with reason
          // `no_location`. Offer the user a one-click override to
          // discard it instead, since the most common other intent
          // ("I sold/discarded this") would otherwise need a trip
          // to /review to express.
          const collection = useCollectionStore()
          toast.withActions(
            `${cardName} marked for review.`,
            [
              {
                label: 'Sold / discarded',
                run: () => collection.resolveReview([
                  { collection_entry_id: removed.physical_copy_id, discard: true },
                ]),
              },
            ],
          )
        }
      } catch (e) {
        this.entries.splice(idx, 0, removed)
        toast.error(e.response?.data?.message || 'Delete failed')
        throw e
      }
    },

    /**
     * Inline-picker shortcuts. Each maps directly onto a backend
     * `mode=...` / `discard=true` call so the action service is the
     * single point of truth for the assemble-related semantics. The
     * `bindAsNewCopy` path is the same backend hook the menu offers
     * for unbound entries; `discard` is the menu's "sold or discarded"
     * action.
     */
    async bindEntryAsNewCopy(deckId, entryId) {
      return this.updateEntry(deckId, entryId, { mode: 'create_new_copy' })
    },

    async growEntryAsNewCopy(deckId, entryId, newQuantity) {
      return this.updateEntry(deckId, entryId, {
        quantity: newQuantity,
        mode: 'create_new_copy',
      })
    },

    async shrinkEntryAndDiscard(deckId, entryId, newQuantity) {
      return this.updateEntry(deckId, entryId, {
        quantity: newQuantity,
        discard: true,
      })
    },

    async destroyEntryAndDiscard(deckId, entryId) {
      return this.removeEntry(deckId, entryId, { discard: true })
    },

    async updateDeck(id, patch) {
      const toast = useToast()
      try {
        const { data } = await api.put(`/decks/${id}`, patch)
        this.deck = data
        await this.loadIllegalities(id)
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Deck update failed')
        throw e
      }
    },

    async setCompanion(id, scryfallId) {
      return this.updateDeck(id, { companion_scryfall_id: scryfallId })
    },

    async setCommander(slot, scryfallId) {
      if (!this.deck) return
      const field = slot === 2 ? 'commander_2_scryfall_id' : 'commander_1_scryfall_id'
      return this.updateDeck(this.deck.id, { [field]: scryfallId })
    },

    /**
     * Promote a deck entry into a commander slot. `slot` is 1 or 2; when null
     * we auto-pick the first empty slot, falling back to slot 1 if both are
     * full. The previous occupant of the chosen slot keeps its deck_entries
     * row (with is_commander cleared) — backend syncCommanderEntries handles
     * the flip.
     *
     * Note: the deck presenter exposes commanders as nested `commander1` /
     * `commander2` objects, not the raw `*_scryfall_id` columns, so we read
     * the current ids off those nested objects and write the column names
     * the API validator expects.
     */
    async promoteEntryToCommander(deckId, entry, slot = null) {
      if (!entry || !this.deck) return
      const sid = entry.scryfall_id
      const c1 = this.deck.commander1?.scryfall_id || null
      const c2 = this.deck.commander2?.scryfall_id || null
      // Already a commander — moving between slots goes through swap instead.
      if (sid === c1 || sid === c2) return

      let target = slot
      if (target === null) {
        if (!c1) target = 1
        else if (!c2) target = 2
        else target = 1
      }
      const field = target === 2 ? 'commander_2_scryfall_id' : 'commander_1_scryfall_id'
      return this.updateDeck(deckId, { [field]: sid })
    },

    /** Clear an entry from whichever commander slot(s) it occupies. */
    async demoteCommander(deckId, entry) {
      if (!entry || !this.deck) return
      const sid = entry.scryfall_id
      const c1 = this.deck.commander1?.scryfall_id || null
      const c2 = this.deck.commander2?.scryfall_id || null
      const patch = {}
      if (c1 === sid) patch.commander_1_scryfall_id = null
      if (c2 === sid) patch.commander_2_scryfall_id = null
      if (!Object.keys(patch).length) return
      return this.updateDeck(deckId, patch)
    },

    /** Swap commander_1 ↔ commander_2 in one PATCH. No-op if either is empty. */
    async swapCommanders(deckId) {
      if (!this.deck) return
      const c1 = this.deck.commander1?.scryfall_id || null
      const c2 = this.deck.commander2?.scryfall_id || null
      if (!c1 || !c2) return
      return this.updateDeck(deckId, {
        commander_1_scryfall_id: c2,
        commander_2_scryfall_id: c1,
      })
    },

    /**
     * Mark a main-zone entry as a signature spell, attached to an oathbreaker
     * entry id. When parentEntryId is null we pick the oathbreaker whose
     * color identity contains every color of the spell; with no match we
     * fall back to the first oathbreaker so legality can flag it.
     */
    async makeSignatureSpell(deckId, entry, parentEntryId = null) {
      if (!entry) return
      let parent = parentEntryId
      if (parent === null) {
        const oathbreakers = this.commanderEntries
        if (oathbreakers.length === 1) {
          parent = oathbreakers[0].id
        } else if (oathbreakers.length > 1) {
          const spellColors = entry.scryfall_card?.color_identity || []
          const match = oathbreakers.find((ob) => {
            const obColors = ob.scryfall_card?.color_identity || []
            return spellColors.every((c) => obColors.includes(c))
          })
          parent = (match || oathbreakers[0]).id
        }
      }
      return this.updateEntry(deckId, entry.id, {
        is_signature_spell: true,
        signature_for_entry_id: parent,
      })
    },

    /** Clear is_signature_spell + signature_for_entry_id, leaving the entry as a normal main-zone card. */
    async demoteSignatureSpell(deckId, entry) {
      if (!entry) return
      return this.updateEntry(deckId, entry.id, {
        is_signature_spell: false,
        signature_for_entry_id: null,
      })
    },

    /**
     * POST /api/decks/{id}/assemble — declare the deck physically built.
     * The backend creates one CE per slot in the deck-location, links
     * each entry's `physical_copy_id`, and (for partial-exclude rows)
     * splits the slot in two.
     *
     * `intent` shape:
     *   { all: bool, sections?: string[], excludes?: [{scryfall_id, zone, qty}] }
     */
    async assembleDeck(id, intent) {
      const toast = useToast()
      try {
        const { data } = await api.post(`/decks/${id}/assemble`, intent)
        // Re-load entries so the new physical_copy_id bindings + split
        // rows from partial-excludes show up immediately.
        await this.loadEntries(id)
        await useCollectionStore().fetchGroups()
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Assemble failed')
        throw e
      }
    },

    /**
     * POST /api/decks/{id}/unassemble — tear down the assembled state.
     * Every CE in the deck-location is marked for review with reason
     * `no_location`. Every entry's physical_copy_id is cleared.
     */
    async unassembleDeck(id) {
      const toast = useToast()
      try {
        const { data } = await api.post(`/decks/${id}/unassemble`)
        await this.loadEntries(id)
        await useCollectionStore().fetchGroups()
        return data
      } catch (e) {
        toast.error(e.response?.data?.message || 'Unassemble failed')
        throw e
      }
    },

    async ignoreIllegality(id, payload) {
      await api.post(`/decks/${id}/illegalities/ignore`, payload)
      await this.loadIllegalities(id)
    },

    async unignoreIllegality(id, payload) {
      const body = { ...payload }
      delete body.expected_count
      await api.post(`/decks/${id}/illegalities/unignore`, body)
      await this.loadIllegalities(id)
    },

    setActiveEntry(entryId) {
      this.activeEntryId = this.activeEntryId === entryId ? null : entryId
    },

    setDragEntry(entryId) {
      this.dragEntryId = entryId
    },

    setUndocked(section, value) {
      if (section === 'side')  this.sideUndocked  = !!value
      if (section === 'maybe') this.maybeUndocked = !!value
    },

    /**
     * Update a filter chip (c / t / r) by re-serializing view.search. Display
     * controls (group / sort / display) live in view.{groupBy,sort,displayMode}
     * and go through setDeckDirective instead — they're no longer search syntax.
     */
    setDeckChip(key, value) {
      if (!DECK_SCHEMA.chipKeys.includes(key)) return
      const parsed = this.parsedView
      const nextChips = { ...parsed.chips, [key]: value || '' }
      this.view.search = serializeQueryGeneric(
        { free: parsed.nameQuery, chips: nextChips },
        DECK_SCHEMA,
      )
    },

    setDeckDirective(key, value) {
      const viewField = DIRECTIVE_TO_VIEW[key]
      if (!viewField) return
      this.view[viewField] = value || DECK_DIRECTIVE_DEFAULTS[key]
    },

    reset() {
      this.deck = null
      this.entries = []
      this.ownedCopiesByScryfallId = {}
      this.illegalities = []
      this.activeEntryId = null
      this.saving = new Set()
      this.sideUndocked = false
      this.maybeUndocked = false
    },
  },
})
