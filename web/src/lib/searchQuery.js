/**
 * Shared search-string parser for chip + free-text queries. Consumers
 * pass a schema describing which token keys act as chips, which act as
 * directives (e.g. sort:, group:), and which string aliases fold into a
 * canonical key (e.g. set: → s).
 *
 * Schema shape:
 *   {
 *     chipKeys:      string[],                // e.g. ['c','t','r','s']
 *     directiveKeys: string[],                // e.g. ['sort','order']
 *     aliases:       { [alias]: canonical },  // e.g. { set: 's' }
 *   }
 *
 * parseSearch result:
 *   {
 *     tokens:     [{ type, value }, ...],         // chip tokens only
 *     nameQuery:  string,                         // free-text remainder
 *     directives: { [directiveKey]: string },     // '' if unset
 *     chips:      { [chipKey]: string },          // '' if unset
 *   }
 *
 * Quoting and full Scryfall syntax (compound expressions, comparators)
 * are out of scope — this only makes dropdown-generated tokens work
 * alongside chip-driven UIs.
 */

function emptyState(schema) {
  const chips = {}
  for (const k of schema.chipKeys) chips[k] = ''
  const directives = {}
  for (const k of schema.directiveKeys) directives[k] = ''
  return { tokens: [], nameQuery: '', directives, chips }
}

export function parseSearch(search, schema) {
  if (!search) return emptyState(schema)
  const chipSet = new Set(schema.chipKeys)
  const dirSet = new Set(schema.directiveKeys)
  const aliases = schema.aliases || {}

  const state = emptyState(schema)
  const nameParts = []

  for (const part of search.trim().split(/\s+/)) {
    if (!part) continue
    const m = part.match(/^([A-Za-z]+):(.+)$/)
    if (!m) {
      nameParts.push(part)
      continue
    }
    let key = m[1].toLowerCase()
    const val = m[2].toLowerCase()
    if (aliases[key]) key = aliases[key]

    if (dirSet.has(key)) {
      state.directives[key] = val
      continue
    }
    if (chipSet.has(key)) {
      state.tokens.push({ type: key, value: val })
      state.chips[key] = val
      continue
    }
    // Unknown prefix → fall through to free text so the raw token isn't lost.
    nameParts.push(part)
  }

  state.nameQuery = nameParts.join(' ')
  return state
}

/**
 * Round-trip a parsed-state-like object back into a normalized string.
 * Stable order: free text, chips in schema order, directives in schema
 * order. Empty values are dropped so the query field stays tidy as chips
 * toggle.
 *
 * State shape (all fields optional):
 *   { free: string|string[], chips: {...}, directives: {...} }
 *
 * The optional `defaults` map drops directive values that match the
 * default (e.g. sort:'name' is implied, so we don't serialize it).
 */
export function serializeQuery(state, schema, defaults = {}) {
  const parts = []
  const free = state.free
  if (free) {
    if (Array.isArray(free)) parts.push(...free.filter(Boolean))
    else if (typeof free === 'string' && free.trim()) parts.push(free.trim())
  }

  const chips = state.chips || {}
  const aliasesInv = {}
  for (const [alias, canonical] of Object.entries(schema.aliases || {})) {
    // Prefer the alias when serializing if it exists (e.g. 's' → 'set:').
    if (!aliasesInv[canonical]) aliasesInv[canonical] = alias
  }
  for (const key of schema.chipKeys) {
    const v = chips[key]
    if (!v) continue
    const prefix = aliasesInv[key] || key
    parts.push(`${prefix}:${v}`)
  }

  const directives = state.directives || {}
  for (const key of schema.directiveKeys) {
    const v = directives[key]
    if (!v) continue
    if (defaults[key] !== undefined && v === defaults[key]) continue
    parts.push(`${key}:${v}`)
  }

  return parts.join(' ')
}
