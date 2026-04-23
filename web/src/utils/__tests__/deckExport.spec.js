import { describe, it, expect } from 'vitest'
import { buildPlainText, buildMoxfieldText, buildJson } from '../deckExport.js'

function entry(name, { quantity = 1, zone = 'main', is_commander = false, scryfall_id = `id-${name}` } = {}) {
  return {
    quantity,
    zone,
    is_commander,
    is_signature_spell: false,
    category: null,
    scryfall_id,
    scryfall_card: { name },
  }
}

const baseDeck = {
  id: 7,
  name: 'Atraxa Superfriends',
  format: 'commander',
  description: '',
  color_identity: 'WUBG',
  commander_1_scryfall_id: 'id-Atraxa, Praetors\' Voice',
  commander_2_scryfall_id: null,
  companion_scryfall_id: null,
}

describe('buildPlainText', () => {
  it('emits Commander / Deck / Sideboard / Maybe sections in order, skipping empty ones', () => {
    const entries = [
      entry("Atraxa, Praetors' Voice", { is_commander: true }),
      entry('Sol Ring'),
      entry('Arcane Signet'),
      entry('Back to Basics', { zone: 'side' }),
    ]
    const text = buildPlainText(baseDeck, entries)
    expect(text).toBe(
      [
        'Commander',
        "1 Atraxa, Praetors' Voice",
        '',
        'Deck',
        '1 Arcane Signet',
        '1 Sol Ring',
        '',
        'Sideboard',
        '1 Back to Basics',
      ].join('\n'),
    )
  })

  it('omits Commander section when deck has no commander', () => {
    const text = buildPlainText(
      { ...baseDeck, commander_1_scryfall_id: null },
      [entry('Lightning Bolt', { quantity: 4 })],
    )
    expect(text).toBe(['Deck', '4 Lightning Bolt'].join('\n'))
  })

  it('excludes commanders from the Deck section', () => {
    const entries = [
      entry("Atraxa, Praetors' Voice", { is_commander: true }),
      entry('Sol Ring'),
    ]
    const text = buildPlainText(baseDeck, entries)
    expect(text).not.toMatch(/Deck\n.*Atraxa/s)
  })
})

describe('buildMoxfieldText', () => {
  it('puts commanders at the top of Deck and drops the Commander header', () => {
    const entries = [
      entry("Atraxa, Praetors' Voice", { is_commander: true }),
      entry('Sol Ring'),
      entry('Back to Basics', { zone: 'side' }),
    ]
    const text = buildMoxfieldText(baseDeck, entries)
    expect(text).toBe(
      [
        'Deck',
        "1 Atraxa, Praetors' Voice",
        '1 Sol Ring',
        '',
        'Sideboard',
        '1 Back to Basics',
      ].join('\n'),
    )
  })

  it('omits Maybe entries entirely', () => {
    const text = buildMoxfieldText(baseDeck, [
      entry('Sol Ring'),
      entry('Rhystic Study', { zone: 'maybe' }),
    ])
    expect(text).not.toContain('Rhystic Study')
    expect(text).not.toContain('Maybe')
  })
})

describe('buildJson', () => {
  it('includes deck metadata and per-entry names, stripping DB ids', () => {
    const entries = [entry('Sol Ring', { quantity: 1 })]
    const parsed = JSON.parse(buildJson(baseDeck, entries))
    expect(parsed.deck.name).toBe('Atraxa Superfriends')
    expect(parsed.deck.format).toBe('commander')
    expect(parsed.entries).toHaveLength(1)
    expect(parsed.entries[0]).toMatchObject({
      quantity: 1,
      zone: 'main',
      is_commander: false,
      name: 'Sol Ring',
      scryfall_id: 'id-Sol Ring',
    })
    // Should not leak internal ids
    expect(parsed.entries[0]).not.toHaveProperty('id')
    expect(parsed.entries[0]).not.toHaveProperty('physical_copy_id')
    expect(parsed.deck).not.toHaveProperty('user_id')
  })
})
