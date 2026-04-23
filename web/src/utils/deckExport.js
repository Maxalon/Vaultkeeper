/**
 * Build export strings and trigger browser downloads for a deck.
 * Pure frontend — consumes the already-loaded deck + entries from the store.
 *
 * Entry shape (from DeckEntryController::presentEntryBare):
 *   { id, quantity, zone, is_commander, is_signature_spell, category,
 *     scryfall_id, scryfall_card: { name, ... } }
 */

function cardName(entry) {
  return entry.scryfall_card?.name || '(Unknown card)'
}

function formatLine(entry) {
  return `${entry.quantity} ${cardName(entry)}`
}

function sortByName(entries) {
  return [...entries].sort((a, b) => cardName(a).localeCompare(cardName(b)))
}

/**
 * Vaultkeeper sectioned format — mirrors DeckImportService::parseText so the
 * exported file re-imports cleanly. Sections:
 *   Commander / Companion / Deck / Sideboard / Maybe
 * Empty sections are omitted.
 */
export function buildPlainText(deck, entries) {
  const commanders = entries.filter((e) => e.is_commander)
  const companions = entries.filter(
    (e) => e.scryfall_id === deck?.companion_scryfall_id,
  )
  const main = entries.filter(
    (e) => e.zone === 'main' && !e.is_commander,
  )
  const side = entries.filter((e) => e.zone === 'side')
  const maybe = entries.filter((e) => e.zone === 'maybe')

  const sections = []
  if (commanders.length) {
    sections.push(['Commander', sortByName(commanders)])
  }
  if (companions.length) {
    sections.push(['Companion', sortByName(companions)])
  }
  if (main.length) {
    sections.push(['Deck', sortByName(main)])
  }
  if (side.length) {
    sections.push(['Sideboard', sortByName(side)])
  }
  if (maybe.length) {
    sections.push(['Maybe', sortByName(maybe)])
  }

  return sections
    .map(([header, items]) => [header, ...items.map(formatLine)].join('\n'))
    .join('\n\n')
}

/**
 * Flat Moxfield / MTGA Arena format — only `Deck` and `Sideboard`. Commanders
 * are listed inline at the top of `Deck`. `Maybe` is dropped (not recognized
 * by Moxfield or Arena). Pastes cleanly into Moxfield, Archidekt, and Arena.
 */
export function buildMoxfieldText(_deck, entries) {
  const commanders = entries.filter((e) => e.is_commander)
  const main = entries.filter((e) => e.zone === 'main' && !e.is_commander)
  const side = entries.filter((e) => e.zone === 'side')

  const deckLines = [
    ...sortByName(commanders).map(formatLine),
    ...sortByName(main).map(formatLine),
  ]
  const sections = []
  if (deckLines.length) sections.push(['Deck', deckLines])
  if (side.length) {
    sections.push(['Sideboard', sortByName(side).map(formatLine)])
  }

  return sections
    .map(([header, lines]) => [header, ...lines].join('\n'))
    .join('\n\n')
}

/**
 * Structured JSON dump — deck metadata + entries with names. Strips DB-only
 * ids (user_id, per-entry id, physical_copy_id) that are meaningless outside
 * this instance.
 */
export function buildJson(deck, entries) {
  const payload = {
    deck: {
      name: deck?.name ?? '',
      format: deck?.format ?? '',
      description: deck?.description ?? '',
      color_identity: deck?.color_identity ?? '',
      commander_1_scryfall_id: deck?.commander_1_scryfall_id ?? null,
      commander_2_scryfall_id: deck?.commander_2_scryfall_id ?? null,
      companion_scryfall_id: deck?.companion_scryfall_id ?? null,
    },
    entries: entries.map((e) => ({
      quantity: e.quantity,
      zone: e.zone,
      is_commander: !!e.is_commander,
      is_signature_spell: !!e.is_signature_spell,
      category: e.category ?? null,
      scryfall_id: e.scryfall_id,
      name: cardName(e),
    })),
  }
  return JSON.stringify(payload, null, 2)
}

function slugify(name) {
  return (name || '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function baseFilename(deck) {
  return slugify(deck?.name) || `deck-${deck?.id ?? 'export'}`
}

const FORMATS = {
  text: {
    build: buildPlainText,
    ext: 'txt',
    mime: 'text/plain;charset=utf-8',
  },
  moxfield: {
    build: buildMoxfieldText,
    ext: 'txt',
    mime: 'text/plain;charset=utf-8',
    suffix: '-moxfield',
  },
  json: {
    build: buildJson,
    ext: 'json',
    mime: 'application/json;charset=utf-8',
  },
}

/**
 * Build the export string, wrap it in a Blob, and trigger a browser download.
 * `format` is 'text' | 'moxfield' | 'json'; unknown falls back to 'text'.
 */
export function downloadDeck(format, deck, entries) {
  const spec = FORMATS[format] || FORMATS.text
  const body = spec.build(deck, entries)
  const blob = new Blob([body], { type: spec.mime })
  const url = URL.createObjectURL(blob)

  const a = document.createElement('a')
  a.href = url
  a.download = `${baseFilename(deck)}${spec.suffix || ''}.${spec.ext}`
  document.body.appendChild(a)
  a.click()
  a.remove()

  URL.revokeObjectURL(url)
}
