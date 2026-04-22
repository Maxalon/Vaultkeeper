<?php

namespace App\Services;

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

    public function __construct(
        private ScryfallService $scryfall,
        private BulkSyncService $bulkSync,
        private DeckController $deckCtrl,
        private DeckEntryController $entryCtrl,
    ) {}

    /**
     * @return array{deck: Deck, imported: int, skipped: int, warnings: string[]}
     */
    public function importFromUrl(User $user, string $url, ?int $groupId): array
    {
        $dto = match (true) {
            $this->looksLikeArchidekt($url) => $this->fetchArchidekt($url),
            $this->looksLikeMoxfield($url)  => $this->fetchMoxfield($url),
            default => throw ValidationException::withMessages([
                'url' => ['URL must be an Archidekt or Moxfield deck link.'],
            ]),
        };
        return $this->materialize($user, $dto, $groupId);
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
        return $this->materialize($user, $dto, $groupId);
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
     *   companion?: ?array{scryfall_id?: ?string, name: string, set?: ?string},
     *   entries: array<int, array{scryfall_id?: ?string, name: string, set?: ?string, quantity: int, zone: string}>
     * }  $dto
     * @return array{deck: Deck, imported: int, skipped: int, warnings: string[]}
     */
    private function materialize(User $user, array $dto, ?int $groupId): array
    {
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
            $user, $dto, $resolved, $idPresent, $groupId, &$warnings, &$imported, &$skipped,
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

            $commanderIds = [];
            foreach ($dto['commanders'] ?? [] as $c) {
                $id = $pick($c);
                if ($id === null) {
                    $warnings[] = "Commander not found: {$c['name']}".(! empty($c['set']) ? " ({$c['set']})" : '');
                    continue;
                }
                $commanderIds[] = $id;
                if (count($commanderIds) >= 2) break;
            }

            $companionId = null;
            if (! empty($dto['companion'])) {
                $companionId = $pick($dto['companion']);
                if ($companionId === null) {
                    $warnings[] = "Companion not found: {$dto['companion']['name']}";
                }
            }

            $deck = Deck::create([
                'user_id'                 => $user->id,
                'name'                    => substr($dto['name'] ?? 'Imported deck', 0, 100),
                'format'                  => $dto['format'] ?? 'commander',
                'description'             => $dto['description'] ?? null,
                'commander_1_scryfall_id' => $commanderIds[0] ?? null,
                'commander_2_scryfall_id' => $commanderIds[1] ?? null,
                'companion_scryfall_id'   => $companionId,
                'group_id'                => $groupId,
            ]);

            // Run the same commander-slot + color-identity reconciliation the
            // normal create flow does, so the commander entry rows exist with
            // is_commander=true and the deck color_identity is populated.
            $this->deckCtrl->syncCommanderEntries($deck);
            $this->deckCtrl->recomputeColorIdentity($deck);

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
    // Source adapters
    // ─────────────────────────────────────────────────────────────────────

    private function looksLikeArchidekt(string $url): bool
    {
        return (bool) preg_match('~archidekt\.com/(api/)?decks/\d+~i', $url);
    }

    private function looksLikeMoxfield(string $url): bool
    {
        return (bool) preg_match('~moxfield\.com/decks/[\w-]+~i', $url);
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

        $commanders = [];
        $companion = null;
        $entries = [];

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

        $formatRaw = strtolower((string) ($json['deckFormat'] ?? ($json['format'] ?? 'commander')));
        $format = self::SOURCE_FORMAT_MAP[$formatRaw] ?? 'commander';

        return [
            'name'        => (string) ($json['name'] ?? "Archidekt deck {$id}"),
            'format'      => $format,
            'description' => $json['description'] ?? null,
            'commanders'  => $commanders,
            'companion'   => $companion,
            'entries'     => $entries,
        ];
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
