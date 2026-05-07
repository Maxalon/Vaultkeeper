const WUBRG = { W: 0, U: 1, B: 2, R: 3, G: 4 }

export function colorIdentityKey(colors) {
  if (!colors || colors.length === 0) return '9'
  if (colors.length === 1) return '0' + WUBRG[colors[0]]
  return (
    '' +
    colors.length +
    [...colors].sort((a, b) => WUBRG[a] - WUBRG[b]).map((c) => WUBRG[c]).join('')
  )
}

export function compareByColorThenName(a, b, dir = 1) {
  const ka = colorIdentityKey(a.card?.colors)
  const kb = colorIdentityKey(b.card?.colors)
  if (ka !== kb) return ka < kb ? -dir : dir
  const na = (a.card?.name || '').toLowerCase()
  const nb = (b.card?.name || '').toLowerCase()
  return na < nb ? -1 : na > nb ? 1 : 0
}
