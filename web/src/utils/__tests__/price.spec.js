import { describe, it, expect } from 'vitest'
import { formatEur, pickPriceFinish, PRICE_PLACEHOLDER } from '../price.js'

describe('formatEur', () => {
  it('returns the placeholder for null/undefined/empty/NaN', () => {
    expect(formatEur(null)).toBe(PRICE_PLACEHOLDER)
    expect(formatEur(undefined)).toBe(PRICE_PLACEHOLDER)
    expect(formatEur('')).toBe(PRICE_PLACEHOLDER)
    expect(formatEur('not-a-number')).toBe(PRICE_PLACEHOLDER)
  })

  it('formats numeric inputs as EUR', () => {
    expect(formatEur(12.5)).toMatch(/12[.,]50/)
    expect(formatEur(12.5)).toMatch(/€/)
  })

  it('accepts string-encoded prices (the API ships decimals as strings)', () => {
    expect(formatEur('0.99')).toMatch(/0[.,]99/)
  })
})

describe('pickPriceFinish', () => {
  const prices = { eur: '1.00', eur_foil: '4.00', eur_etched: '8.00' }

  it('returns null when no prices payload is provided', () => {
    expect(pickPriceFinish(null)).toBeNull()
    expect(pickPriceFinish(undefined)).toBeNull()
  })

  it('reads eur for nonfoil', () => {
    expect(pickPriceFinish(prices)).toBe(1)
    expect(pickPriceFinish(prices, { foil: false, isEtched: false })).toBe(1)
  })

  it('reads eur_foil for foil', () => {
    expect(pickPriceFinish(prices, { foil: true })).toBe(4)
  })

  it('reads eur_etched for etched', () => {
    expect(pickPriceFinish(prices, { isEtched: true })).toBe(8)
  })

  it('falls back foil → nonfoil when foil is missing', () => {
    expect(pickPriceFinish({ eur: '2.00' }, { foil: true })).toBe(2)
  })

  it('falls back etched → foil → nonfoil', () => {
    expect(pickPriceFinish({ eur: '1.00', eur_foil: '4.00' }, { isEtched: true }))
      .toBe(4)
    expect(pickPriceFinish({ eur: '1.00' }, { isEtched: true })).toBe(1)
  })

  it('returns null when every fallback is missing', () => {
    expect(pickPriceFinish({ eur: null, eur_foil: null, eur_etched: null }))
      .toBeNull()
  })
})
