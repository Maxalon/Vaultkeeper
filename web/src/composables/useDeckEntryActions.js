import { computed } from 'vue'
import { useDeckStore } from '../stores/deck'

/**
 * Derive the list of "promote / demote / etc." actions available for a deck
 * entry. The same composable backs both DeckDetailSidebar (rendered as inline
 * buttons) and the right-click context menu on tiles/strips, so menu state
 * stays in one place.
 *
 * Each returned action has:
 *   - id     short stable key
 *   - label  user-visible text
 *   - hint   optional secondary line (used by the menu, ignored by buttons)
 *   - kind   'primary' | 'neutral' | 'danger' — drives styling
 *   - run()  invokes the store action; awaits the round-trip
 */
export function useDeckEntryActions(entryRef) {
  const deck = useDeckStore()

  const format = computed(() => deck.deck?.format || null)
  const supportsCommanders = computed(
    () => format.value === 'commander' || format.value === 'oathbreaker',
  )
  const isOathbreaker = computed(() => format.value === 'oathbreaker')

  const c1Id = computed(() => deck.deck?.commander1?.scryfall_id || null)
  const c2Id = computed(() => deck.deck?.commander2?.scryfall_id || null)
  const c1Name = computed(() => deck.deck?.commander1?.name || null)
  const c2Name = computed(() => deck.deck?.commander2?.name || null)
  const oathbreakerEntries = computed(() => deck.commanderEntries)

  const commanderLabel = computed(() => (isOathbreaker.value ? 'oathbreaker' : 'commander'))
  const spellLabel = 'signature spell'

  function deckId() {
    return deck.deck?.id || null
  }

  const actions = computed(() => {
    const e = entryRef?.value
    if (!e || !deckId()) return []
    const out = []
    const sid = e.scryfall_id

    // ── Commander promote/demote ──────────────────────────────────────────
    if (supportsCommanders.value) {
      if (e.is_commander) {
        out.push({
          id: 'demote-commander',
          label: `Demote from ${commanderLabel.value}`,
          hint: 'Keeps the card in the mainboard',
          kind: 'neutral',
          run: () => deck.demoteCommander(deckId(), e),
        })
      } else if (sid !== c1Id.value && sid !== c2Id.value) {
        // Already a different commander? Skip. Otherwise show the right
        // promote action(s) for the current slot state.
        if (!c1Id.value) {
          out.push({
            id: 'make-commander',
            label: `Make ${commanderLabel.value}`,
            kind: 'primary',
            run: () => deck.promoteEntryToCommander(deckId(), e, 1),
          })
        } else if (!c2Id.value) {
          out.push({
            id: 'make-cocommander',
            label: `Make co-${commanderLabel.value}`,
            hint: `Pairs with ${c1Name.value || 'commander 1'}`,
            kind: 'primary',
            run: () => deck.promoteEntryToCommander(deckId(), e, 2),
          })
        } else {
          out.push({
            id: 'replace-commander-1',
            label: `Replace ${commanderLabel.value} 1`,
            hint: c1Name.value ? `Currently ${c1Name.value}` : null,
            kind: 'primary',
            run: () => deck.promoteEntryToCommander(deckId(), e, 1),
          })
          out.push({
            id: 'replace-commander-2',
            label: `Replace ${commanderLabel.value} 2`,
            hint: c2Name.value ? `Currently ${c2Name.value}` : null,
            kind: 'primary',
            run: () => deck.promoteEntryToCommander(deckId(), e, 2),
          })
        }
      }
    }

    // ── Signature spell promote/demote (oathbreaker only) ─────────────────
    if (isOathbreaker.value && !e.is_commander) {
      if (e.is_signature_spell) {
        out.push({
          id: 'demote-signature-spell',
          label: 'Demote from signature spell',
          hint: 'Keeps the card in the mainboard',
          kind: 'neutral',
          run: () => deck.demoteSignatureSpell(deckId(), e),
        })
      } else if (oathbreakerEntries.value.length <= 1) {
        out.push({
          id: 'make-signature-spell',
          label: `Make ${spellLabel}`,
          hint: oathbreakerEntries.value.length === 1
            ? `For ${oathbreakerEntries.value[0].scryfall_card?.name || 'the oathbreaker'}`
            : 'No oathbreaker set yet — will need one to be valid',
          kind: 'primary',
          run: () => deck.makeSignatureSpell(deckId(), e),
        })
      } else {
        // Multiple oathbreakers — let the user pick which one this attaches to.
        for (const ob of oathbreakerEntries.value) {
          out.push({
            id: `make-signature-spell-${ob.id}`,
            label: `Signature spell for ${ob.scryfall_card?.name || 'oathbreaker'}`,
            kind: 'primary',
            run: () => deck.makeSignatureSpell(deckId(), e, ob.id),
          })
        }
      }
    }

    return out
  })

  return { actions }
}
