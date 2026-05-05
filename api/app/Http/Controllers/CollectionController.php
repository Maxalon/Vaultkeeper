<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Services\CardSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CollectionController extends Controller
{
    public function __construct(private CardSearchService $search) {}

    /** Sortable columns. cmc is intentionally absent — no column to back it yet. */
    private const SORT_FIELDS = [
        'name'             => 'scryfall_cards.name',
        'set_code'         => 'scryfall_cards.set_code',
        'rarity'           => 'scryfall_cards.rarity',
        'collector_number' => 'scryfall_cards.collector_number',
        'condition'        => 'collection_entries.condition',
    ];

    /**
     * GET /api/collection
     *
     * Query params:
     *   - location_id: integer | "unassigned" | omitted
     *   - q:      Scryfall-syntax search (matches catalog). Falls back to
     *             `search` (legacy name-substring) when q is absent.
     *   - sort:   one of SORT_FIELDS (default: name)
     *   - order:  asc|desc (default: asc)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        // Sort via join on scryfall_cards so we can order by card columns.
        // The explicit select() prevents the join from polluting the
        // collection_entries row attributes.
        $sortKey = $request->query('sort', 'name');
        $sortCol = self::SORT_FIELDS[$sortKey] ?? self::SORT_FIELDS['name'];
        $order   = strtolower($request->query('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = CollectionEntry::query()
            ->join('scryfall_cards', 'collection_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
            ->where('collection_entries.user_id', $userId)
            ->with('card')
            // Deck-location copies are represented by their deck_entry on
            // the deck page; surfacing them here too would double-count
            // and let the user accidentally re-shelve a copy out from
            // under its deck. Pending copies stay visible (the dedicated
            // /pending UI lives elsewhere in the stack).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('locations')
                    ->whereColumn('locations.id', 'collection_entries.location_id')
                    ->where('locations.role', Location::ROLE_DECK);
            })
            ->select('collection_entries.*');

        // location_id filter — three possible shapes
        if ($request->has('location_id')) {
            $loc = $request->query('location_id');
            if ($loc === 'unassigned' || $loc === null || $loc === '') {
                $query->whereNull('collection_entries.location_id');
            } else {
                $query->where('collection_entries.location_id', (int) $loc);
            }
        }

        // Full Scryfall-syntax search: route through CardSearchService and
        // intersect via oracle_id. `disable_defaults` because the user is
        // filtering their own collection — hidden-type / playtest defaults
        // would mask cards they actually own.
        //
        // Per-printing operators (set:, cn:) also constrain the joined
        // scryfall_cards row directly. The oracle filter alone would let
        // through any printing the user owns once the oracle has *some*
        // matching printing — e.g. set:SOA would surface an FDN-printed
        // Burst Lightning the user happens to have, which isn't what they
        // asked for. Narrowing on scryfall_cards.set_code restricts the
        // result to entries whose specific printing is in the queried set.
        $warnings = [];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $parsed = $this->search->search($q, ['disable_defaults' => true]);
            $warnings = $parsed['warnings'];
            $query->whereIn(
                'scryfall_cards.oracle_id',
                $parsed['builder']->select('oracle_id')
            );
            $printingFilters = $parsed['printing_filters'] ?? [];
            if (! empty($printingFilters['set'])) {
                $query->where('scryfall_cards.set_code', $printingFilters['set']);
            }
            if (! empty($printingFilters['collector_number'])) {
                $query->where('scryfall_cards.collector_number', $printingFilters['collector_number']);
            }
        } elseif ($search = trim((string) $request->query('search', ''))) {
            // Legacy fallback for callers still passing `search=` (name-only).
            $query->where('scryfall_cards.name', 'like', "%{$search}%");
        }

        $entries = $query->orderBy($sortCol, $order)->get();

        $wantedMap = $this->wantedByDecksMap($entries->pluck('scryfall_id')->unique()->all(), $userId);

        return response()->json([
            'data'     => $entries->map(fn (CollectionEntry $e) => $this->presentList($e, $wantedMap))->values(),
            'warnings' => $warnings,
        ]);
    }

    public function show(CollectionEntry $entry): JsonResponse
    {
        abort_if($entry->user_id !== auth()->id(), 403);

        $entry->loadMissing('card');

        $wantedMap = $this->wantedByDecksMap([$entry->scryfall_id], auth()->id());

        return response()->json($this->presentDetail($entry, $wantedMap));
    }

    public function update(Request $request, CollectionEntry $entry): JsonResponse
    {
        abort_if($entry->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'condition'   => 'sometimes|in:NM,LP,MP,HP,DMG',
            'location_id' => [
                'sometimes',
                'nullable',
                'integer',
                // Must belong to the same user AND be a user-managed location.
                // Auto-managed rows (deck) are off-limits to direct user
                // moves — they're written exclusively by their owning
                // model so the invariants stay coherent.
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $exists = Location::where('id', $value)
                        ->where('user_id', auth()->id())
                        ->where('role', Location::ROLE_USER)
                        ->exists();
                    if (! $exists) {
                        $fail('The selected location is invalid.');
                    }
                },
            ],
            'notes'    => 'sometimes|nullable|string|max:1000',
            'quantity' => 'sometimes|integer|min:1',
            'foil'     => 'sometimes|boolean',
            // Optional optimistic-locking version. When present, the
            // current row's version must match — otherwise 412.
            'version'  => 'sometimes|integer|min:0',
        ]);

        $expectedVersion = array_key_exists('version', $data) ? (int) $data['version'] : null;
        unset($data['version']);

        // The user re-shelving this copy means the "from <deck>" stamp from
        // the last shrink is no longer relevant — clear it in the same
        // write as the location move so the pill disappears. Picking any
        // valid location also resolves a `no_location` review reason.
        if (array_key_exists('location_id', $data)) {
            $data['source_deck_id'] = null;
            $data['source_deck_name_snapshot'] = null;
            $data['source_deck_deleted'] = false;
            $data['review_reason'] = null;
        }

        $previousLocationId = $entry->getOriginal('location_id');

        DB::transaction(function () use ($entry, $data, $expectedVersion) {
            $locked = CollectionEntry::query()
                ->where('id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedVersion !== null) {
                $locked->assertVersion($expectedVersion);
            }
            $locked->update($data);
            // Bring the route-bound model in line with the locked write.
            $entry->setRawAttributes($locked->fresh()->getAttributes(), true);
        });

        if (array_key_exists('location_id', $data)) {
            $affected = array_filter([$previousLocationId, $entry->location_id]);
            foreach (Location::whereIn('id', $affected)->get() as $loc) {
                $loc->refreshSetCodes();
            }
        }

        $entry->load('card');

        $wantedMap = $this->wantedByDecksMap([$entry->scryfall_id], auth()->id());

        return response()->json($this->presentDetail($entry, $wantedMap));
    }

    /**
     * POST /api/collection/batch-move
     *
     * Move multiple entries to a new location in one request.
     */
    public function batchMove(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Cap: a single batchMove fans out to a whereIn().update() over
            // the request's ids. 500 is safely above any plausible UI batch
            // and well below the level where one request would lock-out the
            // collection_entries table for other users.
            'ids'         => 'required|array|min:1|max:500',
            'ids.*'       => 'integer',
            'location_id' => [
                'present',
                'nullable',
                'integer',
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $exists = Location::where('id', $value)
                        ->where('user_id', auth()->id())
                        ->where('role', Location::ROLE_USER)
                        ->exists();
                    if (! $exists) {
                        $fail('The selected location is invalid.');
                    }
                },
            ],
        ]);

        // Capture source locations before the move
        $sourceLocationIds = CollectionEntry::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', $data['ids'])
            ->whereNotNull('location_id')
            ->distinct()
            ->pluck('location_id')
            ->all();

        $count = CollectionEntry::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', $data['ids'])
            ->update(['location_id' => $data['location_id']]);

        // Refresh set_codes on all affected locations (sources + target)
        $affectedIds = array_filter(array_unique([...$sourceLocationIds, $data['location_id']]));
        foreach (Location::whereIn('id', $affectedIds)->get() as $loc) {
            $loc->refreshSetCodes();
        }

        return response()->json(['moved' => $count]);
    }

    /**
     * GET /api/collection/copies?scryfall_id={uuid}
     *
     * Returns the authed user's collection_entries for a single card, joined
     * with location name + type. Used by the deckbuilder's physical-copy
     * dropdown to bind a deck entry to a specific owned copy.
     */
    public function copiesForCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scryfall_id' => 'required|uuid',
        ]);

        $rows = CollectionEntry::query()
            ->where('user_id', auth()->id())
            ->where('scryfall_id', $data['scryfall_id'])
            // Same rationale as index(): a copy already living in a
            // deck-location is owned by that deck, so don't offer it as
            // a binding target for a different deck slot.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('locations')
                    ->whereColumn('locations.id', 'collection_entries.location_id')
                    ->where('locations.role', Location::ROLE_DECK);
            })
            ->with('location:id,name,type')
            ->get();

        return response()->json(
            $rows->map(fn (CollectionEntry $e) => [
                'id'            => $e->id,
                'quantity'      => $e->quantity,
                'condition'     => $e->condition,
                'foil'          => (bool) $e->foil,
                'notes'         => $e->notes,
                'location_id'   => $e->location_id,
                'location_name' => $e->location?->name,
                'location_type' => $e->location?->type,
            ])->values()
        );
    }

    public function destroy(Request $request, CollectionEntry $entry): Response
    {
        abort_if($entry->user_id !== auth()->id(), 403);

        $expectedVersion = $request->has('version')
            ? (int) $request->input('version')
            : null;

        $locationId = null;
        DB::transaction(function () use ($entry, $expectedVersion, &$locationId) {
            $locked = CollectionEntry::query()
                ->where('id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($expectedVersion !== null) {
                $locked->assertVersion($expectedVersion);
            }
            $locationId = $locked->location_id;
            $locked->delete();
        });

        if ($locationId) {
            Location::find($locationId)?->refreshSetCodes();
        }

        return response()->noContent();
    }

    /**
     * Pending-bucket label for a copy, or null when there's no source deck
     * to display. Prefers the live deck record (so renames track), falls
     * back to the snapshot once the deck is gone.
     *
     * @return array{deck_id: ?int, deck_name: string, deleted: bool}|null
     */
    private function presentSourceDeck(CollectionEntry $entry): ?array
    {
        if ($entry->source_deck_id === null && $entry->source_deck_name_snapshot === null) {
            return null;
        }

        return [
            'deck_id'   => $entry->source_deck_id,
            'deck_name' => $entry->source_deck_name_snapshot ?? '',
            'deleted'   => (bool) $entry->source_deck_deleted,
        ];
    }

    /**
     * Build a map [scryfall_id => [{deck_id, deck_name, zone}, ...]] of
     * cards the authenticated user has marked "wanted" (in any zone) in a
     * deck without a physical_copy_id assigned. Each entry carries the
     * zone so the frontend can colour-code by priority — main-deck wants
     * mean more than maybe-board wants.
     *
     * @param  array<int, string>  $scryfallIds
     * @return Collection<string, array<int, array{deck_id: int, deck_name: string, zone: string}>>
     */
    private function wantedByDecksMap(array $scryfallIds, int $userId): Collection
    {
        if (empty($scryfallIds)) {
            return collect();
        }

        return DeckEntry::query()
            ->whereIn('scryfall_id', $scryfallIds)
            ->whereNotNull('wanted')
            ->whereNull('physical_copy_id')
            ->whereHas('deck', fn ($q) => $q->where('user_id', $userId))
            ->with('deck:id,name')
            ->get()
            ->groupBy('scryfall_id')
            ->map(fn ($rows) => $rows
                ->filter(fn ($r) => $r->deck !== null)
                ->map(fn ($r) => [
                    'deck_id'   => $r->deck->id,
                    'deck_name' => $r->deck->name,
                    'zone'      => $r->wanted,
                ])
                ->values()
                ->all());
    }

    /**
     * Compact list-row shape for GET /api/collection.
     *
     * @param  Collection<string, array<int, array{deck_id: int, deck_name: string, zone: string}>>  $wantedMap
     * @return array<string, mixed>
     */
    private function presentList(CollectionEntry $entry, Collection $wantedMap): array
    {
        $card = $entry->card;

        return [
            'id'          => $entry->id,
            'quantity'    => $entry->quantity,
            'condition'   => $entry->condition,
            'foil'        => (bool) $entry->foil,
            'notes'       => $entry->notes,
            'location_id' => $entry->location_id,
            'version'     => (int) ($entry->version ?? 0),
            'source_deck' => $this->presentSourceDeck($entry),
            'created_at'  => $entry->created_at?->toIso8601String(),
            'card'        => $card ? [
                'scryfall_id'      => $card->scryfall_id,
                'name'             => $card->name,
                'set_code'         => $card->set_code,
                'collector_number' => $card->collector_number,
                'rarity'           => $card->rarity,
                'colors'           => $card->colors,
                'is_dfc'           => (bool) $card->is_dfc,
                'mana_cost'        => $card->mana_cost,
                'type_line'        => $card->type_line,
                'image_small'      => $card->image_small,
                'image_normal'     => $card->image_normal,
                'image_large'      => $card->image_large,
                'image_small_back' => $card->image_small_back,
                'image_normal_back'=> $card->image_normal_back,
                'image_large_back' => $card->image_large_back,
            ] : null,
            'wanted_by_decks' => $wantedMap->get($entry->scryfall_id, []),
        ];
    }

    /**
     * Full-detail shape for GET /api/collection/{id} and PATCH responses.
     *
     * @param  Collection<string, array<int, array{deck_id: int, deck_name: string, zone: string}>>  $wantedMap
     * @return array<string, mixed>
     */
    private function presentDetail(CollectionEntry $entry, Collection $wantedMap): array
    {
        $card = $entry->card;

        return [
            'id'          => $entry->id,
            'quantity'    => $entry->quantity,
            'condition'   => $entry->condition,
            'foil'        => (bool) $entry->foil,
            'notes'       => $entry->notes,
            'location_id' => $entry->location_id,
            'version'     => (int) ($entry->version ?? 0),
            'source_deck' => $this->presentSourceDeck($entry),
            'card'        => $card ? [
                'scryfall_id'      => $card->scryfall_id,
                'name'             => $card->name,
                'set_code'         => $card->set_code,
                'collector_number' => $card->collector_number,
                'rarity'           => $card->rarity,
                'is_dfc'           => (bool) $card->is_dfc,
                'mana_cost'        => $card->mana_cost,
                'mana_cost_back'   => $card->mana_cost_back,
                'type_line'        => $card->type_line,
                'type_line_back'   => $card->type_line_back,
                'oracle_text'      => $card->oracle_text,
                'oracle_text_back' => $card->oracle_text_back,
                'power'            => $card->power,
                'toughness'        => $card->toughness,
                'loyalty'          => $card->loyalty,
                'colors'           => $card->colors,
                'image_small'      => $card->image_small,
                'image_normal'     => $card->image_normal,
                'image_large'      => $card->image_large,
                'image_small_back' => $card->image_small_back,
                'image_normal_back'=> $card->image_normal_back,
                'image_large_back' => $card->image_large_back,
                'legalities'       => $card->legalities,
            ] : null,
            'wanted_by_decks' => $wantedMap->get($entry->scryfall_id, []),
        ];
    }
}
