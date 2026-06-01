/**
 * EUR price formatting + finish-aware price selection.
 *
 * Vaultkeeper sources prices from Scryfall's bulk feed, which mirrors
 * Cardmarket trend prices in the `prices.eur*` triad. The backend
 * exposes the three columns verbatim on every card payload as
 * `card.prices = { eur, eur_foil, eur_etched, captured_on }`, with
 * any of the value fields nullable.
 */

const FORMATTER = new Intl.NumberFormat(undefined, {
  style: 'currency',
  currency: 'EUR',
})

/** Sentinel rendered when no price is available. */
export const PRICE_PLACEHOLDER = '—'

/**
 * Format a numeric or numeric-string EUR amount. Returns the placeholder
 * for null / undefined / unparseable values so callers don't need to
 * branch on missing data — the UI just reads "—".
 */
export function formatEur(amount) {
  if (amount === null || amount === undefined || amount === '') return PRICE_PLACEHOLDER
  const num = typeof amount === 'number' ? amount : Number(amount)
  if (!Number.isFinite(num)) return PRICE_PLACEHOLDER
  return FORMATTER.format(num)
}

/**
 * Pick the right EUR column for a card given a finish flavour. Etched
 * falls back to foil and then to nonfoil; foil falls back to nonfoil;
 * nonfoil only reads `eur`. Returns a Number or null.
 *
 * @param {Object|null|undefined} prices  card.prices payload
 * @param {{foil?: boolean, isEtched?: boolean}} [opts]
 */
export function pickPriceFinish(prices, { foil = false, isEtched = false } = {}) {
  if (!prices) return null
  const pickField = (field) => {
    const v = prices[field]
    if (v === null || v === undefined || v === '') return null
    const num = Number(v)
    return Number.isFinite(num) ? num : null
  }
  if (isEtched) {
    return pickField('eur_etched') ?? pickField('eur_foil') ?? pickField('eur')
  }
  if (foil) {
    return pickField('eur_foil') ?? pickField('eur')
  }
  return pickField('eur')
}

/**
 * Convenience wrapper: format the right-finish price for a card+entry pair.
 * Returns the placeholder when the card has no price data for the finish.
 */
export function formatPriceForEntry(card, { foil = false, isEtched = false } = {}) {
  const value = pickPriceFinish(card?.prices, { foil, isEtched })
  return formatEur(value)
}

/**
 * Numeric value for sorting a card by price. Finish-aware when foil/etched
 * are known (mirrors pickPriceFinish), and falls back across the remaining
 * finishes so a card that only carries a foil/etched price still sorts on a
 * real number rather than dropping to "no price". Returns null only when the
 * card has no price in any finish, letting callers push those rows last.
 *
 * @param {Object|null|undefined} prices  card.prices payload
 * @param {{foil?: boolean, isEtched?: boolean}} [opts]
 */
export function priceSortValue(prices, { foil = false, isEtched = false } = {}) {
  if (!prices) return null
  // `isEtched` is the broadest fallback chain (etched → foil → nonfoil), so
  // it backstops whichever finish the caller asked for.
  return pickPriceFinish(prices, { foil, isEtched }) ?? pickPriceFinish(prices, { isEtched: true })
}
