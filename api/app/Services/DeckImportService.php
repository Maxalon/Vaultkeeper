<?php

namespace App\Services;

use App\Exceptions\DeckSourceConflictException;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\DeckEntryController;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\LocationGroup;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports a deck from an Archidekt URL, a Moxfield URL, or a pasted plain-text
 * decklist. All three sources flow through the same pipeline:
 *
 *   raw input → normalized DTO → resolve names to scryfall_ids
 *             → create Deck + DeckEntry rows
 *
 * Unresolved cards are accumulated as warnings; the deck is still created
 * with whatever resolved, matching ManaBoxImportService's partial-success
 * contract.
 */
class DeckImportService
{
    private const ALLOWED_FORMATS = ['commander', 'oathbreaker', 'pauper', 'standard', 'modern'];

    /** Moxfield/Archidekt format tokens we can map directly. Anything else falls back to commander. */
    private const SOURCE_FORMAT_MAP = [
        'commander'        => 'commander',
        'edh'              => 'commander',
        'oathbreaker'      => 'oathbreaker',
        'pauper'           => 'pauper',
        'standard'         => 'standard',
        'modern'           => 'modern',
    ];

    /**
     * Archidekt's `deckFormat` field is a numeric ID, not a string. IDs come
     * from pyrchidekt's enum (https://github.com/linkian209/pyrchidekt). Only
     * IDs that map onto a Vaultkeeper-supported format are listed; anything
     * else falls back to commander, matching SOURCE_FORMAT_MAP's behaviour.
     */
    private const ARCHIDEKT_FORMAT_IDS = [
        1  => 'standard',
        2  => 'modern',
        3  => 'commander',
        6  => 'pauper',
        14 => 'oathbreaker',
    ];

    public function __construct(
        private ScryfallService $scryfall,
        private BulkSyncService $bulkSync,
        private DeckController $deckCtrl,
        private DeckEntryController $entryCtrl,
    ) {}

    /**
     * @param  'create'|'update'|'auto'  $mode
     *   - 'create': always make a new deck (allows intentional duplicates)
     *   - 'update': overwrite an existing same-source deck; error if none found
     *   - 'auto':   create if no match exists; otherwise return a conflict so
     *               the caller (controller) can prompt the user
     * @return array{deck: Deck, imported: int, skipped: int, warnings: string[], action: 'created'|'updated'}
     */
    public function importFromUrl(User $user, string $url, ?int $groupId, string $mode = 'create'): array
    {
        [$source, $sourceId] = $this->parseSource($url);
        $dto = match ($source) {
            'archidekt' => $this->fetchArchidekt($url),
            'moxfield'  => $this->fetchMoxfield($url),
            default => throw ValidationException::withMessages([
                'url' => ['URL must be an Archidekt or Moxfield deck link.'],
            ]),
        };

        $existing = $this->findExistingBySource($user->id, $source, $sourceId);

        if ($mode === 'update') {
            if (! $existing) {
                throw ValidationException::withMessages([
                    'url' => ['No existing import to update — use create instead.'],
                ]);
            }
            return $this->materialize($user, $dto, $groupId, $source, $sourceId, $existing) + ['action' => 'updated'];
        }

        if ($mode === 'auto' && $existing) {
            // Caller decides what to do — surface the conflict.
            throw new DeckSourceConflictException($existing);
        }

        return $this->materialize($user, $dto, $groupId, $source, $sourceId, null) + ['action' => 'created'];
    }

    /**
     * Look up an existing deck by (user, source, source_id) without
     * triggering an import. Used by the controller to render a pre-import
     * confirmation when the user already has this deck.
     */
    public function findExistingBySource(int $userId, ?string $source, ?string $sourceId): ?Deck
    {
        if ($source === null || $sourceId === null) return null;
        return Deck::where('user_id', $userId)
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Read the source slug + source-specific id out of a deck URL.
     * Returns [null, null] for unrecognized URLs (text imports etc.).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseSource(string $url): array
    {
        if (preg_match('~archidekt\.com/(?:api/)?decks/(\d+)~i', $url, $m)) {
            return ['archidekt', (string) $m[1]];
        }
        if (preg_match('~moxfield\.com/decks/([\w-]+)~i', $url, $m)) {
            return ['moxfield', (string) $m[1]];
        }
        return [null, null];
    }

    /**
     * @return array{deck: Deck, imported: int, skipped: int, warnings: string[]}
     */
    public function importFromText(
        User $user,
        string $text,
        string $name,
        string $format,
        ?int $groupId,
    ): array {
        if (! in_array($format, self::ALLOWED_FORMATS, true)) {
            throw ValidationException::withMessages(['format' => ['Unsupported format.']]);
        }
        $dto = $this->parseText($text);
        $dto['name']   = $name;
        $dto['format'] = $format;
        return $this->materialize($user, $dto, $groupId, null, null, null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pipeline step 2: name resolution + deck/entry creation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{
     *   name: string,
     *   format: string,
     *   description?: ?string,
     *   commanders: array<int, array{scryfall_id?: ?string, name: string, set?: ?string}>,
     *   signature_spells?: array<int, array{scryfall_id?: ?string, name: string, set?: ?string}>,
     *   companion?: ?array{scryfall_id?: ?string, name: string, set?: ?string},
     *   entries: array<int, array{scryfall_id?: ?string, name: string, set?: ?string, quantity: int, zone: string}>
     * }  $dto
     * @param  Deck|null  $existing  When non-null, the deck's cards/commanders/format/
     *                               description/name are overwritten in place. The deck's
     *                               group_id, sort_order, and ignored illegalities are
     *                               preserved (the user may have organised them locally).
     * @return array{deck: Deck, imported: int, skipped: int, warnings: string[]}
     */
    private function materialize(
        User $user,
        array $dto,
        ?int $groupId,
        ?string $source = null,
        ?string $sourceId = null,
        ?Deck $existing = null,
    ): array {
        $warnings = [];

        // Archidekt and Moxfield both give us Scryfall UUIDs directly; only
        // the plain-text path needs name-based resolution. Collect both in
        // one pass so we can batch each kind to the fastest fetch path.
        $idsToResolve = [];
        $namesToResolve = [];
        $collect = function (array $row) use (&$idsToResolve, &$namesToResolve) {
            if (! empty($row['scryfall_id'])) {
                $idsToResolve[] = (string) $row['scryfall_id'];
            } elseif (! empty($row['name'])) {
                $namesToResolve[] = [
                    'name' => $row['name'],
                    'set'  => $row['set'] ?? null,
                    'collector_number' => $row['collector_number'] ?? null,
                ];
            }
        };
        foreach ($dto['commanders'] ?? [] as $c) $collect($c);
        foreach ($dto['signature_spells'] ?? [] as $s) $collect($s);
        if (! empty($dto['companion'])) $collect($dto['companion']);
        foreach ($dto['entries'] ?? [] as $e) $collect($e);

        $idPresent = $this->ensureIdsPresent($idsToResolve, $warnings);
        $resolved  = $this->resolveNames($namesToResolve, $warnings);

        if ($groupId !== null) {
            $ownsGroup = LocationGroup::where('id', $groupId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $ownsGroup) {
                throw ValidationException::withMessages([
                    'group_id' => ['The selected group does not belong to you.'],
                ]);
            }
        }

        $imported = 0;
        $skipped  = 0;

        /** @var Deck $deck */
        $deck = DB::transaction(function () use (
            $user, $dto, $resolved, $idPresent, $groupId, $source, $sourceId, $existing, &$warnings, &$imported, &$skipped,
        ) {
            $pick = function (array $row) use ($resolved, $idPresent): ?string {
                if (! empty($row['scryfall_id']) && isset($idPresent[(string) $row['scryfall_id']])) {
                    return (string) $row['scryfall_id'];
                }
                return $this->pickResolved(
                    $resolved,
                    $row['name'] ?? '',
                    $row['set'] ?? null,
                    $row['collector_number'] ?? null,
                );
            };

            $format = $dto['format'] ?? 'commander';

            $commanderIds  = [];
            $signatureIds  = [];
            foreach ($dto['commanders'] ?? [] as $c) {
                $id = $pick($c);
                if ($id === null) {
                    $warnings[] = "Commander not found: {$c['name']}".(! empty($c['set']) ? " ({$c['set']})" : '');
                    continue;
                }
                // Archidekt's "Commander" category is overloaded for Oathbreaker
                // decks: users sometimes mark both the planeswalker and the
                // signature spell with it. Reclassify any Instant/Sorcery as a
                // signature spell so it doesn't end up in commander_*_scryfall_id.
                if ($format === 'oathbreaker' && $this->isInstantOrSorcery($id)) {
                    $signatureIds[] = $id;
                    continue;
                }
                $commanderIds[] = $id;
                if (count($commanderIds) >= 2) break;
            }

            foreach ($dto['signature_spells'] ?? [] as $s) {
                $id = $pick($s);
                if ($id === null) {
                    $warnings[] = "Signature spell not found: {$s['name']}".(! empty($s['set']) ? " ({$s['set']})" : '');
                    continue;
                }
                if (! in_array($id, $signatureIds, true)) {
                    $signatureIds[] = $id;
                }
            }

            $companionId = null;
            if (! empty($dto['companion'])) {
                $companionId = $pick($dto['companion']);
                if ($companionId === null) {
                    $warnings[] = "Companion not found: {$dto['companion']['name']}";
                }
            }

            $attributes = [
                'name'                    => substr($dto['name'] ?? 'Imported deck', 0, 100),
                'format'                  => $format,
                'description'             => $dto['description'] ?? null,
                'commander_1_scryfall_id' => $commanderIds[0] ?? null,
                'commander_2_scryfall_id' => $commanderIds[1] ?? null,
                'companion_scryfall_id'   => $companionId,
            ];

            if ($existing) {
                // Update mode: overwrite source-derived fields, preserve the
                // user's local organisation (group_id, sort_order, ignored
                // illegalities). Wipe DeckEntry rows and let the loop below
                // re-insert them so card-level edits sync cleanly.
                $existing->update($attributes);
                DeckEntry::where('deck_id', $existing->id)->delete();
                $deck = $existing;
            } else {
                $deck = Deck::create($attributes + [
                    'user_id'   => $user->id,
                    'source'    => $source,
                    'source_id' => $sourceId,
                    'group_id'  => $groupId,
                ]);
            }

            // Run the same commander-slot + color-identity reconciliation the
            // normal create flow does, so the commander entry rows exist with
            // is_commander=true and the deck color_identity is populated.
            $this->deckCtrl->syncCommanderEntries($deck);
            $this->deckCtrl->recomputeColorIdentity($deck);

            if (! empty($signatureIds)) {
                $this->createSignatureSpellEntries($deck, $signatureIds);
            }

            foreach ($dto['entries'] ?? [] as $e) {
                $scryfallId = $pick($e);
                if ($scryfallId === null) {
                    $warnings[] = "Card not found: {$e['name']}".(! empty($e['set']) ? " ({$e['set']})" : '');
                    $skipped += (int) $e['quantity'];
                    continue;
                }

                // Skip duplicates: syncCommanderEntries already created a row
                // for the commander(s). If the source also listed them in
                // mainboard (Moxfield does), don't double-insert.
                if (in_array($scryfallId, $commanderIds, true)) {
                    continue;
                }
                // Same for signature spells: createSignatureSpellEntries above
                // already inserted them.
                if (in_array($scryfallId, $signatureIds, true)) {
                    continue;
                }

                $card = ScryfallCard::where('scryfall_id', $scryfallId)->first();
                if ($card === null) continue; // shouldn't happen post-resolve, but be defensive.

                // Coalesce duplicate rows (e.g. multiple "4 Sol Ring" lines)
                // onto a single entry with summed quantity.
                $zone = $e['zone'] ?? 'main';
                $existing = DeckEntry::where('deck_id', $deck->id)
                    ->where('scryfall_id', $scryfallId)
                    ->where('zone', $zone)
                    ->first();

                if ($existing) {
                    $existing->quantity += (int) $e['quantity'];
                    $existing->save();
                } else {
                    DeckEntry::create([
                        'deck_id'     => $deck->id,
                        'scryfall_id' => $scryfallId,
                        'quantity'    => max(1, (int) $e['quantity']),
                        'zone'        => $zone,
                        'category'    => $this->entryCtrl->autoCategory($card),
                    ]);
                }
                $imported += (int) $e['quantity'];
            }

            return $deck;
        });

        return [
            'deck'     => $deck->fresh(['entries.card', 'commander1', 'commander2', 'companion', 'ignoredIllegalities']),
            'imported' => $imported,
            'skipped'  => $skipped,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate that every pre-resolved Scryfall UUID (from Archidekt/Moxfield)
     * exists in our local mirror. Any that don't are fetched from Scryfall
     * and persisted so the deck_entries FK lands. Returns the set of IDs
     * that are now known-good, as `[id => true]`.
     *
     * @param  string[]  $ids
     * @param  string[]  &$warnings
     * @return array<string, true>
     */
    private function ensureIdsPresent(array $ids, array &$warnings): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) return [];

        $present = ScryfallCard::whereIn('scryfall_id', $ids)
            ->pluck('scryfall_id')
            ->all();
        $known = array_flip($present);

        $missing = array_values(array_diff($ids, $present));
        if (empty($missing)) return $known;

        try {
            $cards = $this->scryfall->fetchCardCollection($missing);
        } catch (RuntimeException $e) {
            $warnings[] = 'Scryfall fallback unavailable: '.$e->getMessage();
            return $known;
        }

        if (empty($cards)) return $known;

        $this->bulkSync->loadMultiWordSubtypes();
        $now  = Carbon::now();
        $rows = [];
        foreach ($cards as $card) {
            $row = $this->bulkSync->applyBulkCardData($card, $now);
            if ($row !== null) {
                $rows[] = $row;
                $known[(string) $card['id']] = true;
            }
        }
        if (! empty($rows)) {
            $this->bulkSync->flushScryfallCards($rows);
        }

        foreach ($missing as $id) {
            if (! isset($known[$id])) {
                $warnings[] = "Card not in local catalog and Scryfall fetch failed for id {$id}";
            }
        }

        return $known;
    }

    /**
     * Resolve a list of {name, set?, collector_number?} identifiers to
     * scryfall_ids. Local DB first: (set, collector_number) is the unique
     * key and wins if both are provided; then (name, set); then name-only.
     * Misses fall through to Scryfall's /cards/collection in a single
     * batched call.
     *
     * Returns an array `name_lower => [setLower + '/' + cnLower | '' => scryfall_id]`
     * so callers can prefer the exact printing and fall back down the
     * specificity ladder.
     *
     * @param  array<int, array{name: string, set: ?string, collector_number?: ?string}>  $needs
     * @param  string[]  &$warnings
     * @return array<string, array<string, string>>
     */
    private function resolveNames(array $needs, array &$warnings): array
    {
        $byKey = []; // name_lower → variant_key → scryfall_id
        // variant_key shape: "" (any), "<set>|" (any cn in that set), "<set>|<cn>" (exact)

        // De-dupe by (name, set, cn) so we never resolve the same triple twice.
        $triples = [];
        foreach ($needs as $n) {
            $name = $this->normalizeName((string) $n['name']);
            if ($name === '') continue;
            $set  = $n['set'] !== null ? strtolower(trim((string) $n['set'])) : '';
            $cn   = isset($n['collector_number']) && $n['collector_number'] !== null
                ? strtolower(trim((string) $n['collector_number']))
                : '';
            $triples[$name.'|'.$set.'|'.$cn] = ['name' => $name, 'set' => $set, 'cn' => $cn];
        }

        // ── Pass 1: local DB ─────────────────────────────────────────────
        $unresolved = [];
        foreach ($triples as $p) {
            $id = $this->localLookup($p['name'], $p['set'], $p['cn']);
            if ($id !== null) {
                // Record under every specificity level so later pickResolved
                // calls with less context still hit.
                $nameKey = strtolower($p['name']);
                if ($p['set'] !== '' && $p['cn'] !== '') {
                    $byKey[$nameKey][$p['set'].'|'.$p['cn']] = $id;
                }
                if ($p['set'] !== '') {
                    $byKey[$nameKey][$p['set'].'|'] = $byKey[$nameKey][$p['set'].'|'] ?? $id;
                }
                $byKey[$nameKey][''] = $byKey[$nameKey][''] ?? $id;
            } else {
                $unresolved[] = $p;
            }
        }

        if (empty($unresolved)) {
            return $byKey;
        }

        // ── Pass 2: Scryfall fallback ────────────────────────────────────
        try {
            // Scryfall's /cards/collection accepts {name, set} but not
            // {name, set, collector_number}, so drop the cn here. It's
            // still honoured on the local side (the upsert later makes
            // future imports hit the cn variant locally).
            $identifiers = array_map(
                static fn ($p) => ['name' => $p['name'], 'set' => $p['set'] !== '' ? $p['set'] : null],
                $unresolved,
            );
            $hits = $this->scryfall->fetchCardCollectionByName($identifiers);
        } catch (RuntimeException $e) {
            $warnings[] = 'Scryfall fallback unavailable: '.$e->getMessage();
            $hits = [];
        }

        if (! empty($hits)) {
            // Persist the newly-discovered printings so repeat imports hit
            // the local cache and the deck_entries FK check passes.
            $this->bulkSync->loadMultiWordSubtypes();
            $now = Carbon::now();
            $rows = [];
            foreach ($hits as $card) {
                $row = $this->bulkSync->applyBulkCardData($card, $now);
                if ($row !== null) $rows[] = $row;
            }
            if (! empty($rows)) {
                $this->bulkSync->flushScryfallCards($rows);
            }

            foreach ($unresolved as $p) {
                $name = $p['name'];
                $set  = $p['set'];
                $key1 = strtolower($name).'|'.$set;
                $key2 = strtolower($name).'|';
                $card = $hits[$key1] ?? $hits[$key2] ?? null;
                if ($card === null || empty($card['id'])) continue;

                $nameKey = strtolower($name);
                $id      = (string) $card['id'];
                if ($set !== '') {
                    $byKey[$nameKey][$set.'|'] = $byKey[$nameKey][$set.'|'] ?? $id;
                }
                $byKey[$nameKey][''] = $byKey[$nameKey][''] ?? $id;
            }
        }

        return $byKey;
    }

    /**
     * Look up a card in our local scryfall_cards mirror. Matches the most
     * specific tuple the caller provided: (set, collector_number) > (set) >
     * (name). Returns the matched scryfall_id or null.
     */
    private function localLookup(string $name, string $set, string $cn = ''): ?string
    {
        // Exact printing: (set, collector_number) is unique — skip the name
        // match so we survive minor name differences (alt art / flavor
        // variants where the printed name differs subtly from Scryfall's).
        if ($set !== '' && $cn !== '') {
            $exact = ScryfallCard::where('set_code', $set)
                ->where('collector_number', $cn)
                ->value('scryfall_id');
            if ($exact !== null) return (string) $exact;
        }

        $query = ScryfallCard::query()->where('name', $name);
        if ($set !== '') {
            $scoped = (clone $query)->where('set_code', $set)->value('scryfall_id');
            if ($scoped !== null) return (string) $scoped;
        }
        $any = $query->value('scryfall_id');
        return $any !== null ? (string) $any : null;
    }

    /**
     * Select the scryfall_id for a (name, set?, cn?) triple out of the
     * resolved map. Prefers the most specific variant key, falls back
     * down the ladder.
     *
     * @param  array<string, array<string, string>>  $resolved
     */
    private function pickResolved(array $resolved, string $name, ?string $set, ?string $cn = null): ?string
    {
        $key = strtolower($this->normalizeName($name));
        if (! isset($resolved[$key])) return null;
        $bucket  = $resolved[$key];
        $setKey  = $set !== null ? strtolower(trim($set)) : '';
        $cnKey   = $cn  !== null ? strtolower(trim($cn)) : '';
        if ($setKey !== '' && $cnKey !== '' && isset($bucket[$setKey.'|'.$cnKey])) {
            return $bucket[$setKey.'|'.$cnKey];
        }
        if ($setKey !== '' && isset($bucket[$setKey.'|'])) {
            return $bucket[$setKey.'|'];
        }
        if (isset($bucket[''])) return $bucket[''];
        // Last-ditch: any printing we recorded.
        return reset($bucket) ?: null;
    }

    /**
     * DFC cards show up in exports as "Front // Back". Our scryfall_cards
     * rows store the same combined name, so first try the full string and
     * only fall back to the front face via the Scryfall pass.
     */
    private function normalizeName(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    }

    /** Return the first value in the list that is a non-empty string. */
    private function firstString(array $values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && $v !== '') return $v;
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oathbreaker helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Type-line check used to demote misclassified Oathbreaker "commanders"
     * (Archidekt's "Commander" category gets applied to signature spells in
     * a lot of user decks). The portion before the em-dash is what counts;
     * "Tribal Sorcery" still matches Sorcery.
     */
    private function isInstantOrSorcery(string $scryfallId): bool
    {
        $card = ScryfallCard::where('scryfall_id', $scryfallId)->first();
        if ($card === null) return false;
        $parts = preg_split('/\x{2014}/u', $card->type_line ?? '', 2);
        $pre = trim($parts[0] ?? '');
        return str_contains($pre, 'Instant') || str_contains($pre, 'Sorcery');
    }

    /**
     * Insert deck_entries for the deck's signature spells and link each one
     * to an oathbreaker via signature_for_entry_id. Pairing logic:
     *   - 1 oathbreaker → all spells attach to it.
     *   - 2 oathbreakers → match each spell to the first oathbreaker whose
     *     color identity is a superset of the spell's. If no match (or the
     *     deck is malformed) the spell attaches to the first oathbreaker
     *     and the legality engine flags it.
     *
     * @param  string[]  $signatureIds
     */
    private function createSignatureSpellEntries(Deck $deck, array $signatureIds): void
    {
        // If no oathbreaker is set, signatureFor stays null — the legality
        // engine surfaces those as "not attached to an oathbreaker".
        $oathbreakerEntries = DeckEntry::where('deck_id', $deck->id)
            ->where('is_commander', true)
            ->with('card')
            ->get();

        foreach ($signatureIds as $scryfallId) {
            $spell = ScryfallCard::where('scryfall_id', $scryfallId)->first();
            if ($spell === null) continue;

            $parent = $this->matchOathbreaker($spell, $oathbreakerEntries);

            $existing = DeckEntry::where('deck_id', $deck->id)
                ->where('scryfall_id', $scryfallId)
                ->where('zone', 'main')
                ->first();

            if ($existing) {
                $existing->update([
                    'is_signature_spell'     => true,
                    'signature_for_entry_id' => $parent?->id,
                    'quantity'               => 1,
                ]);
            } else {
                DeckEntry::create([
                    'deck_id'                => $deck->id,
                    'scryfall_id'            => $scryfallId,
                    'quantity'               => 1,
                    'zone'                   => 'main',
                    'category'               => $this->entryCtrl->autoCategory($spell),
                    'is_signature_spell'     => true,
                    'signature_for_entry_id' => $parent?->id,
                ]);
            }
        }
    }

    /**
     * Pick the oathbreaker entry whose color identity contains every color
     * in the signature spell's identity. Falls back to the first oathbreaker
     * (or null if none exist) so legality can flag the broken pairing.
     */
    private function matchOathbreaker(ScryfallCard $spell, \Illuminate\Support\Collection $oathbreakers): ?DeckEntry
    {
        if ($oathbreakers->isEmpty()) return null;
        if ($oathbreakers->count() === 1) return $oathbreakers->first();

        $spellColors = array_map('strtoupper', (array) ($spell->color_identity ?? []));
        foreach ($oathbreakers as $ob) {
            $obColors = array_map('strtoupper', (array) ($ob->card->color_identity ?? []));
            if (empty(array_diff($spellColors, $obColors))) {
                return $ob;
            }
        }
        return $oathbreakers->first();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Source adapters / user-level listing (bulk import)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pull a username out of a profile URL, or return the input as-is when
     * already a bare username. Accepts archidekt.com/u/<name>,
     * moxfield.com/users/<name>, or a raw "<name>" string.
     */
    public function extractUsername(string $sourceOrUrl, string $input): string
    {
        $trim = trim($input);
        if ($trim === '') {
            throw ValidationException::withMessages(['username' => ['Username is required.']]);
        }

        if (preg_match('~archidekt\.com/u/([\w.\-]+)~i', $trim, $m)) {
            return $m[1];
        }
        if (preg_match('~moxfield\.com/users?/([\w.\-]+)~i', $trim, $m)) {
            return $m[1];
        }
        if (preg_match('~^[\w.\-]+$~', $trim)) {
            return $trim;
        }
        throw ValidationException::withMessages(['username' => ['Could not read a username from the input.']]);
    }

    /** Browsery default headers — Moxfield's WAF rejects bare cURL/PHP UAs. */
    private const BROWSER_HEADERS = [
        'User-Agent'      => 'Mozilla/5.0 (Vaultkeeper/1.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'Accept'          => 'application/json,text/plain,*/*',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    /**
     * List every public deck for an Archidekt username.
     *
     * The deck listing only carries `parentFolderId` per row, not the folder
     * name — so we collect those IDs, fetch each folder's metadata via the
     * detail route (`/api/decks/folders/<id>/`), and build a flattened
     * "Parent / Child" path that the caller maps to LocationGroups.
     *
     * @return array<int, array{id:int, name:string, url:string, folder_path:?string}>
     */
    public function listArchidektUserDecks(string $username): array
    {
        // Pass 1: walk paginated deck listing.
        $url = 'https://archidekt.com/api/decks/v3/?ownerUsername='.urlencode($username).'&pageSize=48';
        $rawDecks = [];
        $folderIds = [];
        $safety = 50; // hard cap on pages so a misbehaving API can't loop forever

        while ($url !== null && $safety-- > 0) {
            $response = Http::withHeaders(self::BROWSER_HEADERS)->timeout(15)->get($url);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'username' => ["Archidekt returned HTTP {$response->status()} for user '{$username}'."],
                ]);
            }

            $json = $response->json();
            $results = is_array($json) ? ($json['results'] ?? []) : [];
            foreach ($results as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) continue;
                $folderId = isset($row['parentFolderId']) ? (int) $row['parentFolderId'] : null;
                if ($folderId !== null && $folderId > 0) $folderIds[$folderId] = true;
                $rawDecks[] = [
                    'id'        => $id,
                    'name'      => (string) ($row['name'] ?? "Archidekt deck {$id}"),
                    'folder_id' => $folderId,
                ];
            }
            $url = is_array($json) ? ($json['next'] ?? null) : null;
        }

        // Pass 2: resolve folder IDs to paths. Best-effort — if Archidekt
        // declines the folder route we still group decks by ID under a
        // "Folder #<id>" label so the source structure is preserved.
        $folderPaths = $this->resolveArchidektFolderPaths(array_keys($folderIds));

        return array_map(static function (array $d) use ($folderPaths) {
            $fid  = $d['folder_id'];
            $path = $fid !== null && isset($folderPaths[$fid]) ? $folderPaths[$fid] : null;
            return [
                'id'          => $d['id'],
                'name'        => $d['name'],
                'url'         => "https://archidekt.com/decks/{$d['id']}/",
                'folder_path' => $path,
            ];
        }, $rawDecks);
    }

    /**
     * Resolve a set of Archidekt folder IDs to flattened paths. Walks parents
     * via BFS so nested folders surface as "Parent / Child". The detail
     * response includes the parent's NAME inline, so we pre-populate parents
     * eagerly and only re-fetch them to discover grandparents. Per-folder
     * fetch failures fall back to "Folder #<id>" so the import still
     * creates a group.
     *
     * Archidekt's implicit root folder ("Home") is excluded from the path:
     * decks directly under it return `null` (→ ungrouped in Vaultkeeper) and
     * descendants drop the "Home / " prefix so the local layout matches the
     * user's mental model.
     *
     * @param  int[]  $rootIds
     * @return array<int, ?string>  null = deck should be ungrouped
     */
    private function resolveArchidektFolderPaths(array $rootIds): array
    {
        // id => ['name' => str, 'parent' => ?int, 'fetched' => bool]
        $folders = [];
        $queue = array_values(array_filter(array_unique($rootIds), fn ($i) => $i > 0));
        $rounds = 0;

        while (! empty($queue) && $rounds++ < 8) {
            $next = [];
            foreach ($queue as $id) {
                if (isset($folders[$id]) && $folders[$id]['fetched']) continue;

                $row = $this->fetchArchidektFolder($id);
                if ($row === null) {
                    // Mark as fetched-failed so we don't retry, but keep any
                    // pre-populated name from a child's response.
                    $folders[$id] = ($folders[$id] ?? ['name' => '', 'parent' => null]) + ['fetched' => true];
                    continue;
                }
                $folders[$id] = [
                    'name'    => $row['name'],
                    'parent'  => $row['parent_id'],
                    'fetched' => true,
                ];

                // The leaf response gives us the parent's name for free —
                // pre-populate so a failed parent fetch still has a label.
                if ($row['parent_id'] !== null && $row['parent_name'] !== null
                    && empty($folders[$row['parent_id']]['fetched'])) {
                    $folders[$row['parent_id']] = [
                        'name'    => $folders[$row['parent_id']]['name'] ?? $row['parent_name'],
                        'parent'  => null,    // unknown until we fetch
                        'fetched' => false,
                    ];
                }
                if ($row['parent_id'] !== null && empty($folders[$row['parent_id']]['fetched'])) {
                    $next[] = $row['parent_id'];
                }
            }
            $queue = $next;
        }

        // Identify the user's root folder ("Home" by default) — the one
        // whose parent is null after a successful fetch. Decks living in
        // it should land ungrouped in Vaultkeeper, and its name should
        // not be prefixed onto the path of any descendant. There's
        // exactly one such folder per Archidekt account.
        $rootFolderId = null;
        foreach ($folders as $id => $folder) {
            if ($folder['parent'] === null && ! empty($folder['fetched']) && $folder['name'] !== '') {
                $rootFolderId = $id;
                break;
            }
        }

        $paths = [];
        foreach ($rootIds as $id) {
            // Decks directly under the root → no group at all (null path).
            if ($rootFolderId !== null && $id === $rootFolderId) {
                $paths[$id] = null;
                continue;
            }

            $name = $folders[$id]['name'] ?? '';
            if ($name === '') {
                $paths[$id] = "Folder #{$id}";
                continue;
            }
            $parts = [];
            $cursor = $id;
            $depth = 0;
            while ($cursor !== null && isset($folders[$cursor]) && $depth++ < 12) {
                // Stop at the root: its name ("Home") is implicit and
                // shouldn't appear as a prefix on every group.
                if ($cursor === $rootFolderId) break;
                $part = trim($folders[$cursor]['name']);
                if ($part !== '') array_unshift($parts, $part);
                $cursor = $folders[$cursor]['parent'];
            }
            $paths[$id] = ! empty($parts) ? implode(' / ', $parts) : "Folder #{$id}";
        }
        return $paths;
    }

    /**
     * Fetch one Archidekt folder by id.
     *
     * Archidekt returns `parentFolder` as a `{id, name}` object on the detail
     * route. We capture both pieces so the caller can pre-populate the
     * parent's name (saves one fetch in the common 2-level-deep case).
     *
     * @return array{name:string, parent_id:?int, parent_name:?string}|null
     */
    private function fetchArchidektFolder(int $id): ?array
    {
        try {
            $resp = Http::withHeaders(self::BROWSER_HEADERS)
                ->timeout(10)
                ->get("https://archidekt.com/api/decks/folders/{$id}/");
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->successful()) return null;
        $j = $resp->json();
        if (! is_array($j)) return null;

        $name = (string) ($j['name'] ?? '');
        if ($name === '') return null;

        $parentId = null;
        $parentName = null;
        $parent = $j['parentFolder'] ?? $j['parent'] ?? null;
        if (is_array($parent)) {
            $parentId   = isset($parent['id']) ? (int) $parent['id'] : null;
            $parentName = isset($parent['name']) ? (string) $parent['name'] : null;
        } elseif (is_int($parent) || (is_string($parent) && ctype_digit($parent))) {
            $parentId = (int) $parent;
        }

        return [
            'name'        => $name,
            'parent_id'   => $parentId,
            'parent_name' => $parentName,
        ];
    }

    /**
     * Walk the (id → parent) graph and produce the full path for every
     * folder, joined with " / ". Cycles or missing parents are tolerated
     * by capping recursion depth.
     *
     * @param  array<int, array{id:int, name:string, parent:?int}>  $folders
     * @return array<int, string>  folder id → "Parent / Child"
     */
    private function buildArchidektFolderPaths(array $folders): array
    {
        $byId = [];
        foreach ($folders as $f) $byId[$f['id']] = $f;

        $paths = [];
        foreach ($byId as $id => $_) {
            $parts = [];
            $cursor = $id;
            $depth = 0;
            while ($cursor !== null && isset($byId[$cursor]) && $depth++ < 12) {
                array_unshift($parts, $byId[$cursor]['name']);
                $cursor = $byId[$cursor]['parent'] ?: null;
            }
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
            if (! empty($parts)) $paths[$id] = implode(' / ', $parts);
        }
        return $paths;
    }

    /**
     * List every public deck for a Moxfield username.
     *
     * Moxfield's listing exposes `folderName` directly on each deck, so we
     * don't need a separate folder-tree call. Pagination is `pageNumber`-
     * based with `totalPages` in the response.
     *
     * @return array<int, array{id:string, name:string, url:string, folder_path:?string}>
     */
    public function listMoxfieldUserDecks(string $username): array
    {
        $decks = [];
        $page = 1;
        $totalPages = 1;
        $safety = 50;

        // Moxfield's API host changes occasionally and is gated behind
        // Cloudflare. Send browser-like headers + Referer to maximize the
        // chance the WAF lets us through.
        $headers = self::BROWSER_HEADERS + [
            'Referer' => 'https://www.moxfield.com/',
            'Origin'  => 'https://www.moxfield.com',
        ];

        while ($page <= $totalPages && $safety-- > 0) {
            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->get('https://api2.moxfield.com/v2/users/'.urlencode($username).'/decks', [
                    'pageSize'   => 100,
                    'pageNumber' => $page,
                ]);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'username' => ["Moxfield returned HTTP {$response->status()} for user '{$username}'."],
                ]);
            }

            $json = $response->json();
            if (! is_array($json)) break;
            $totalPages = (int) ($json['totalPages'] ?? 1);
            foreach ($json['data'] ?? [] as $row) {
                $publicId = (string) ($row['publicId'] ?? '');
                if ($publicId === '') continue;
                $folder = $row['folderName'] ?? ($row['folder']['name'] ?? null);
                $decks[] = [
                    'id'          => $publicId,
                    'name'        => (string) ($row['name'] ?? "Moxfield deck {$publicId}"),
                    'url'         => "https://www.moxfield.com/decks/{$publicId}",
                    'folder_path' => $folder !== null && $folder !== '' ? (string) $folder : null,
                ];
            }
            $page++;
        }

        return $decks;
    }

    /**
     * @return array{
     *   name: string, format: string, description: ?string,
     *   commanders: array<int, array{name: string, set: ?string}>,
     *   companion: ?array{name: string, set: ?string},
     *   entries: array<int, array{name: string, set: ?string, quantity: int, zone: string}>
     * }
     */
    private function fetchArchidekt(string $url): array
    {
        if (! preg_match('~archidekt\.com/(?:api/)?decks/(\d+)~i', $url, $m)) {
            throw ValidationException::withMessages(['url' => ['Could not read Archidekt deck id from URL.']]);
        }
        $id = (int) $m[1];

        $response = Http::withHeaders(['User-Agent' => 'Vaultkeeper/1.0', 'Accept' => 'application/json'])
            ->timeout(15)
            ->get("https://archidekt.com/api/decks/{$id}/");

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => ["Archidekt returned HTTP {$response->status()} for deck {$id}."],
            ]);
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['cards'])) {
            throw ValidationException::withMessages(['url' => ['Archidekt response did not contain any cards.']]);
        }

        $commanders      = [];
        $signatureSpells = [];
        $companion       = null;
        $entries         = [];

        foreach ($json['cards'] as $c) {
            $qty = (int) ($c['quantity'] ?? 0);
            if ($qty <= 0) continue;

            $cardData = $c['card'] ?? [];
            $edition  = $cardData['edition'] ?? [];
            $name     = (string) ($cardData['oracleCard']['name'] ?? ($cardData['name'] ?? ''));
            if ($name === '') continue;
            $set      = isset($edition['editioncode']) ? (string) $edition['editioncode'] : null;

            // Archidekt ships the Scryfall UUID per card under `card.uid`
            // (sometimes `card.scryfallId`). Preferring it skips all the
            // name+set matching fuzz and nails the exact printing the
            // user picked inside Archidekt.
            $scryfallId = $this->firstString([
                $cardData['uid']         ?? null,
                $cardData['scryfallId']  ?? null,
                $cardData['scryfall_id'] ?? null,
            ]);

            $cats = array_map('strtolower', (array) ($c['categories'] ?? []));

            $row = [
                'scryfall_id' => $scryfallId,
                'name' => $name,
                'set'  => $set,
            ];

            // "Signature Spell" wins over "Commander" — Archidekt sometimes
            // tags signature spells with both, and treating them as commanders
            // would land them in commander_*_scryfall_id incorrectly.
            if (in_array('signature spell', $cats, true) || in_array('signature spells', $cats, true)) {
                $signatureSpells[] = $row;
                continue;
            }
            if (in_array('commander', $cats, true)) {
                $commanders[] = $row;
                continue;
            }
            if (in_array('companion', $cats, true)) {
                $companion = $row;
                continue;
            }

            $entries[] = $row + ['quantity' => $qty, 'zone' => $this->archidektZone($c)];
        }

        $format = $this->resolveArchidektFormat($json['deckFormat'] ?? ($json['format'] ?? null));

        return [
            'name'             => (string) ($json['name'] ?? "Archidekt deck {$id}"),
            'format'           => $format,
            'description'      => $json['description'] ?? null,
            'commanders'       => $commanders,
            'signature_spells' => $signatureSpells,
            'companion'        => $companion,
            'entries'          => $entries,
        ];
    }

    /**
     * Archidekt's `deckFormat` field is a numeric integer (1=Standard,
     * 2=Modern, 3=Commander, 14=Oathbreaker, ...). A handful of older or
     * mocked responses send a lowercase string instead, so accept both.
     */
    private function resolveArchidektFormat(mixed $raw): string
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
            return self::ARCHIDEKT_FORMAT_IDS[(int) $raw] ?? 'commander';
        }
        if (is_string($raw)) {
            return self::SOURCE_FORMAT_MAP[strtolower(trim($raw))] ?? 'commander';
        }
        return 'commander';
    }

    /** Archidekt encodes zone via card category name or a boolean flag. */
    private function archidektZone(array $c): string
    {
        $cats = array_map('strtolower', (array) ($c['categories'] ?? []));
        foreach ($cats as $cat) {
            if (str_contains($cat, 'sideboard')) return 'side';
            if (str_contains($cat, 'maybeboard') || $cat === 'maybe') return 'maybe';
        }
        return 'main';
    }

    /**
     * @return array{
     *   name: string, format: string, description: ?string,
     *   commanders: array<int, array{name: string, set: ?string}>,
     *   companion: ?array{name: string, set: ?string},
     *   entries: array<int, array{name: string, set: ?string, quantity: int, zone: string}>
     * }
     */
    private function fetchMoxfield(string $url): array
    {
        if (! preg_match('~moxfield\.com/decks/([\w-]+)~i', $url, $m)) {
            throw ValidationException::withMessages(['url' => ['Could not read Moxfield deck id from URL.']]);
        }
        $publicId = $m[1];

        $response = Http::withHeaders(['User-Agent' => 'Vaultkeeper/1.0', 'Accept' => 'application/json'])
            ->timeout(15)
            ->get("https://api2.moxfield.com/v3/decks/all/{$publicId}");

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => ["Moxfield returned HTTP {$response->status()} for deck {$publicId}."],
            ]);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ValidationException::withMessages(['url' => ['Moxfield response was not JSON.']]);
        }

        // Moxfield v3 nests boards under `boards`. Each board has a `cards`
        // map keyed by an internal id, each containing `{quantity, card: {...}}`.
        $boards = $json['boards'] ?? [];

        $rowFor = function (array $card): array {
            return [
                'scryfall_id' => $this->firstString([
                    $card['scryfall_id'] ?? null,
                    $card['scryfallId']  ?? null,
                ]),
                'name' => (string) ($card['name'] ?? ''),
                'set'  => $card['set'] ?? null,
            ];
        };

        $commanders = [];
        foreach ($boards['commanders']['cards'] ?? [] as $row) {
            $r = $rowFor($row['card'] ?? []);
            if ($r['name'] === '' && empty($r['scryfall_id'])) continue;
            $commanders[] = $r;
        }

        $companion = null;
        $companionRow = null;
        if (! empty($boards['companions']['cards'])) {
            $companionRow = reset($boards['companions']['cards']);
        }
        if ($companionRow) {
            $r = $rowFor($companionRow['card'] ?? []);
            if ($r['name'] !== '' || ! empty($r['scryfall_id'])) {
                $companion = $r;
            }
        }

        $entries = [];
        $zoneMap = [
            'mainboard'  => 'main',
            'sideboard'  => 'side',
            'maybeboard' => 'maybe',
        ];
        foreach ($zoneMap as $key => $zone) {
            foreach ($boards[$key]['cards'] ?? [] as $row) {
                $qty = (int) ($row['quantity'] ?? 0);
                if ($qty <= 0) continue;
                $r = $rowFor($row['card'] ?? []);
                if ($r['name'] === '' && empty($r['scryfall_id'])) continue;
                $entries[] = $r + ['quantity' => $qty, 'zone' => $zone];
            }
        }

        $formatRaw = strtolower((string) ($json['format'] ?? 'commander'));
        $format = self::SOURCE_FORMAT_MAP[$formatRaw] ?? 'commander';

        return [
            'name'        => (string) ($json['name'] ?? "Moxfield deck {$publicId}"),
            'format'      => $format,
            'description' => $json['description'] ?? null,
            'commanders'  => $commanders,
            'companion'   => $companion,
            'entries'     => $entries,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Plain-text parser
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Regex: "<qty>[x] <name> [(SET) [123] [*F*] [extra]]".
     *
     * Captures: qty, name, optional set (2-6 alphanumerics inside (...) or
     * [...]), optional collector number (alphanumeric, preserves alt-art
     * suffixes like "234s"). Trailing junk is tolerated ONLY inside the set
     * group — that way lines without a set (e.g. "1 Sol Ring") don't have
     * their names gobbled by a greedy tail matcher.
     */
    private const CARD_LINE = '/^(?<qty>\d+)x?\s+(?<name>[^\(\[\n]+?)(?:\s*[\(\[](?<set>[A-Za-z0-9]{2,6})[\)\]](?:\s*(?<cn>[A-Za-z0-9]+))?(?:\s+.*)?)?\s*$/';

    /**
     * @return array{
     *   name: string, format: string, description: ?string,
     *   commanders: array<int, array{name: string, set: ?string}>,
     *   companion: ?array{name: string, set: ?string},
     *   entries: array<int, array{name: string, set: ?string, quantity: int, zone: string}>
     * }
     */
    private function parseText(string $text): array
    {
        $commanders = [];
        $companion  = null;
        $entries    = [];

        // `zone` tracks the main/side/maybe board; `role` tracks whether the
        // current section is actually the commander/companion area (in which
        // case matching lines don't become mainboard entries).
        $zone = 'main';
        $role = 'card';

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
                continue;
            }

            $lower = strtolower($trim);
            // Strip trailing ":" from section headers.
            $lower = rtrim($lower, ':');

            switch ($lower) {
                case 'commander':
                case 'commanders':
                    $role = 'commander';
                    continue 2;
                case 'companion':
                    $role = 'companion';
                    continue 2;
                case 'deck':
                case 'main':
                case 'mainboard':
                    $zone = 'main';
                    $role = 'card';
                    continue 2;
                case 'side':
                case 'sideboard':
                    $zone = 'side';
                    $role = 'card';
                    continue 2;
                case 'maybe':
                case 'maybeboard':
                    $zone = 'maybe';
                    $role = 'card';
                    continue 2;
            }

            if (! preg_match(self::CARD_LINE, $trim, $m)) continue;
            $qty  = max(1, (int) $m['qty']);
            $name = $this->normalizeName((string) $m['name']);
            $set  = ! empty($m['set']) ? strtolower($m['set']) : null;
            $cn   = ! empty($m['cn']) ? strtolower((string) $m['cn']) : null;
            if ($name === '') continue;

            $row = ['name' => $name, 'set' => $set, 'collector_number' => $cn];
            if ($role === 'commander') {
                $commanders[] = $row;
                continue;
            }
            if ($role === 'companion') {
                $companion = $row;
                continue;
            }
            $entries[] = $row + ['quantity' => $qty, 'zone' => $zone];
        }

        return [
            'name'        => '',    // filled by importFromText
            'format'      => '',    // filled by importFromText
            'description' => null,
            'commanders'  => $commanders,
            'companion'   => $companion,
            'entries'     => $entries,
        ];
    }
}
