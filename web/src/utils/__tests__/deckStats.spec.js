import { describe, it, expect } from 'vitest'
import {
  costPipsByColor,
  producerCardsByColor,
  cmcBuckets,
  avgManaValue,
  totalManaValue,
} from '../deckStats.js'

function entry(card, quantity = 1) {
  return { quantity, scryfall_card: card }
}

describe('costPipsByColor', () => {
  it('counts single-color pips weighted by quantity', () => {
    const entries = [
      entry({ mana_cost: '{W}{W}{1}' }, 2),
      entry({ mana_cost: '{U}' }, 1),
    ]
    const pips = costPipsByColor(entries)
    expect(pips.W).toBe(4)
    expect(pips.U).toBe(1)
    expect(pips.C).toBe(0)
  })

  it('splits hybrid pips 0.5/0.5', () => {
    const pips = costPipsByColor([entry({ mana_cost: '{W/U}' })])
    expect(pips.W).toBeCloseTo(0.5)
    expect(pips.U).toBeCloseTo(0.5)
  })

  it('counts phyrexian pips as full color', () => {
    const pips = costPipsByColor([entry({ mana_cost: '{W/P}{W/P}' })])
    expect(pips.W).toBe(2)
  })

  it('counts 2-generic hybrid as 0.5 of color', () => {
    const pips = costPipsByColor([entry({ mana_cost: '{2/W}' })])
    expect(pips.W).toBeCloseTo(0.5)
  })
})

describe('producerCardsByColor', () => {
  it('uses produced_mana when present', () => {
    const entries = [
      entry({ produced_mana: ['W', 'U'], color_identity: ['W'] }, 2),
    ]
    const out = producerCardsByColor(entries)
    expect(out.W).toBe(2)
    expect(out.U).toBe(2)
  })

  it('falls back to color_identity for lands without produced_mana', () => {
    const entries = [
      entry({ type_line: 'Basic Land — Plains', color_identity: ['W'] }, 5),
    ]
    const out = producerCardsByColor(entries)
    expect(out.W).toBe(5)
  })

  it('skips non-producers without ramp/land fallback', () => {
    const entries = [
      entry({ type_line: 'Creature — Elf', color_identity: ['G'] }, 1),
    ]
    const out = producerCardsByColor(entries)
    expect(out.G).toBe(0)
  })

  it('uses fallback for ramp-tagged non-land', () => {
    const entries = [
      entry({
        type_line: 'Creature — Elf',
        oracle_tags: ['mana-dork'],
        color_identity: ['G'],
      }),
    ]
    expect(producerCardsByColor(entries).G).toBe(1)
  })
})

describe('cmcBuckets', () => {
  it('buckets by integer CMC and excludes lands', () => {
    const entries = [
      entry({ cmc: 0, type_line: 'Creature' }),
      entry({ cmc: 2, type_line: 'Creature' }, 3),
      entry({ cmc: 8, type_line: 'Creature' }),
      entry({ cmc: 0, type_line: 'Basic Land — Plains' }),
    ]
    const buckets = cmcBuckets(entries)
    expect(buckets[0]).toBe(1)
    expect(buckets[2]).toBe(3)
    expect(buckets['8+']).toBe(1)
  })

  it('groups CMC >= 8 together', () => {
    const entries = [
      entry({ cmc: 9, type_line: 'Creature' }),
      entry({ cmc: 12, type_line: 'Creature' }),
    ]
    expect(cmcBuckets(entries)['8+']).toBe(2)
  })
})

describe('avgManaValue / totalManaValue', () => {
  it('computes averages excluding lands', () => {
    const entries = [
      entry({ cmc: 2, type_line: 'Creature' }, 2),
      entry({ cmc: 4, type_line: 'Creature' }, 1),
      entry({ cmc: 0, type_line: 'Basic Land' }, 10),
    ]
    expect(avgManaValue(entries)).toBeCloseTo((2 + 2 + 4) / 3)
    expect(totalManaValue(entries)).toBe(2 * 2 + 4)
  })

  it('returns 0 for empty deck', () => {
    expect(avgManaValue([])).toBe(0)
    expect(totalManaValue([])).toBe(0)
  })
})
