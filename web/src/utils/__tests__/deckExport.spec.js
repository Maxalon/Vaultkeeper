import { describe, it, expect, vi } from 'vitest'
import {
  buildPlainText,
  buildMoxfieldText,
  buildDeckText,
  copyDeckToClipboard,
} from '../deckExport.js'

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

describe('buildDeckText', () => {
  it('dispatches to the Vaultkeeper format for "text"', () => {
    const entries = [entry('Sol Ring')]
    expect(buildDeckText('text', baseDeck, entries)).toBe(
      buildPlainText(baseDeck, entries),
    )
  })

  it('dispatches to the Moxfield format for "moxfield"', () => {
    const entries = [entry('Sol Ring')]
    expect(buildDeckText('moxfield', baseDeck, entries)).toBe(
      buildMoxfieldText(baseDeck, entries),
    )
  })

  it('falls back to the Vaultkeeper format for unknown formats', () => {
    const entries = [entry('Sol Ring')]
    expect(buildDeckText('bogus', baseDeck, entries)).toBe(
      buildPlainText(baseDeck, entries),
    )
  })
})

describe('copyDeckToClipboard', () => {
  it('writes the serialized deck to navigator.clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    const original = globalThis.navigator
    Object.defineProperty(globalThis, 'navigator', {
      value: { clipboard: { writeText } },
      configurable: true,
    })

    try {
      const entries = [entry('Sol Ring')]
      await copyDeckToClipboard('moxfield', baseDeck, entries)
      expect(writeText).toHaveBeenCalledWith(
        buildMoxfieldText(baseDeck, entries),
      )
    } finally {
      Object.defineProperty(globalThis, 'navigator', {
        value: original,
        configurable: true,
      })
    }
  })
})
