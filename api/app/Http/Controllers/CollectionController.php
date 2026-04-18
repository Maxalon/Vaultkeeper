<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class CollectionController extends Controller
{
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
     *   - search: name substring (case insensitive)
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

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('scryfall_cards.name', 'like', "%{$search}%");
        }

        $entries = $query->orderBy($sortCol, $order)->get();

        $wantedMap = $this->wantedByDecksMap($entries->pluck('scryfall_id')->unique()->all(), $userId);

        return response()->json(
            $entries->map(fn (CollectionEntry $e) => $this->presentList($e, $wantedMap))->values()
        );
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
                // Must belong to the same user
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $exists = \App\Models\Location::where('id', $value)
                        ->where('user_id', auth()->id())
                        ->exists();
                    if (! $exists) {
                        $fail('The selected location is invalid.');
                    }
                },
            ],
            'notes'    => 'sometimes|nullable|string|max:1000',
            'quantity' => 'sometimes|integer|min:1',
            'foil'     => 'sometimes|boolean',
        ]);

        $entry->update($data);

        if (array_key_exists('location_id', $data)) {
            $affected = array_filter([$entry->getOriginal('location_id'), $entry->location_id]);
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
            'ids'         => 'required|array|min:1',
            'ids.*'       => 'integer',
            'location_id' => [
                'present',
                'nullable',
                'integer',
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $exists = \App\Models\Location::where('id', $value)
                        ->where('user_id', auth()->id())
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

    public function destroy(CollectionEntry $entry): Response
    {
        abort_if($entry->user_id !== auth()->id(), 403);

        $locationId = $entry->location_id;
        $entry->delete();

        if ($locationId) {
            Location::find($locationId)?->refreshSetCodes();
        }

        return response()->noContent();
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
