/**
 * Card-color → background style helper. Used as the skeleton placeholder
 * behind a card image on strip-style rows (CardStrip for the collection,
 * CatalogStrip for the catalog). Extracted so both strips agree on the
 * WUBRG palette and multi-color fallback (gradient for two, gold for 3+).
 */

const COLOR_HUE = {
  W: '#f8f4d6',
  U: '#0e68ab',
  B: '#1a1410',
  R: '#d3202a',
  G: '#00733e',
}

// Canonical MTG ordering: White, Blue, Black, Red, Green.
const WUBRG_ORDER = { W: 0, U: 1, B: 2, R: 3, G: 4 }

export function sortWUBRG(colors) {
  return [...(colors || [])]
    .filter((c) => c in WUBRG_ORDER)
    .sort((a, b) => WUBRG_ORDER[a] - WUBRG_ORDER[b])
}

/**
 * Returns an inline-style object whose `background` renders the card's
 * color identity. Colorless/artifact/land → neutral dark; mono → flat;
 * two colors → linear gradient; three or more → MTG gold.
 */
export function colorSkeletonStyle(colors) {
  const cs = sortWUBRG(colors)
  if (cs.length === 0) return { background: '#3a3a42' }
  if (cs.length === 1) return { background: COLOR_HUE[cs[0]] }
  if (cs.length === 2) {
    return {
      background: `linear-gradient(90deg, ${COLOR_HUE[cs[0]]} 0%, ${COLOR_HUE[cs[1]]} 100%)`,
    }
  }
  return { background: '#c9a227' }
}
