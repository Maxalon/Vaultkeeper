/**
 * Pure helpers for the Analysis tab. Operate only on main-zone deck entries;
 * filtering is the caller's responsibility. All functions weight by quantity.
 */

const COLORS = ['W', 'U', 'B', 'R', 'G', 'C']

function emptyColorMap() {
  const o = {}
  for (const c of COLORS) o[c] = 0
  return o
}

function isLand(card) {
  return (card?.type_line || '').includes('Land')
}

function cmc(card) {
  const v = Number(card?.cmc)
  return Number.isFinite(v) ? v : 0
}

/**
 * Parse a Scryfall mana_cost string into its { symbol, count, weights } parts.
 * Hybrid { W/U } counts 0.5 to each; phyrexian { W/P } counts 1 to that color;
 * 2-generic hybrid { 2/W } contributes 0.5 to the color and the rest to
 * generic (which this helper discards — color stats only).
 */
export function costPipsByColor(entries) {
  const tally = emptyColorMap()
  for (const e of entries) {
    const cost = e?.scryfall_card?.mana_cost || ''
    const qty = e?.quantity || 1
    const parts = cost.match(/\{[^}]+\}/g) || []
    for (const raw of parts) {
      const inner = raw.slice(1, -1).toUpperCase()
      // Exact single color symbol
      if (/^[WUBRG]$/.test(inner)) {
        tally[inner] += qty
        continue
      }
      if (inner === 'C') {
        tally.C += qty
        continue
      }
      // Hybrid "W/U" etc
      const hybrid = inner.match(/^([WUBRG])\/([WUBRG])$/)
      if (hybrid) {
        tally[hybrid[1]] += 0.5 * qty
        tally[hybrid[2]] += 0.5 * qty
        continue
      }
      // Phyrexian "W/P"
      const phyrexian = inner.match(/^([WUBRG])\/P$/)
      if (phyrexian) {
        tally[phyrexian[1]] += qty
        continue
      }
      // 2-generic hybrid "2/W"
      const twoHybrid = inner.match(/^2\/([WUBRG])$/)
      if (twoHybrid) {
        tally[twoHybrid[1]] += 0.5 * qty
        continue
      }
      // Generic / X / snow / other — ignored for color stats.
    }
  }
  return tally
}

/**
 * Cards that produce mana, bucketed by the colors they produce. Cards with
 * `produced_mana` populated use that; otherwise fall back to color_identity
 * if the card is a Land or its oracle_tags hint at ramp.
 */
export function producerCardsByColor(entries) {
  const tally = emptyColorMap()
  for (const e of entries) {
    const card = e?.scryfall_card
    if (!card) continue
    const qty = e.quantity || 1
    let colors = card.produced_mana
    if (!Array.isArray(colors) || colors.length === 0) {
      const tags = card.oracle_tags || []
      const rampy = tags.includes('ramp') || tags.includes('mana-rock') || tags.includes('mana-dork')
      if (isLand(card) || rampy) {
        const ci = card.color_identity || []
        colors = ci.length === 0 ? ['C'] : ci
      } else {
        continue
      }
    }
    for (const c of colors) {
      const upper = String(c).toUpperCase()
      if (tally[upper] !== undefined) tally[upper] += qty
    }
  }
  return tally
}

export function cmcBuckets(entries) {
  const buckets = { 0: 0, 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0, 7: 0, '8+': 0 }
  for (const e of entries) {
    const card = e?.scryfall_card
    if (!card || isLand(card)) continue
    const qty = e.quantity || 1
    const v = cmc(card)
    const key = v >= 8 ? '8+' : String(Math.floor(v))
    if (buckets[key] !== undefined) buckets[key] += qty
  }
  return buckets
}

export function avgManaValue(entries) {
  let sum = 0
  let qty = 0
  for (const e of entries) {
    const card = e?.scryfall_card
    if (!card || isLand(card)) continue
    const q = e.quantity || 1
    sum += cmc(card) * q
    qty += q
  }
  return qty === 0 ? 0 : sum / qty
}

export function totalManaValue(entries) {
  let sum = 0
  for (const e of entries) {
    const card = e?.scryfall_card
    if (!card || isLand(card)) continue
    sum += cmc(card) * (e.quantity || 1)
  }
  return sum
}

export const COLOR_ORDER = COLORS
