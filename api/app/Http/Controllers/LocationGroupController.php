<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\DeckIgnoredIllegality;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Services\DeckLegalityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationGroupController extends Controller
{
    public function __construct(private DeckLegalityService $legality) {}

    /**
     * GET /api/location-groups
     *
     * Returns the sidebar as a recursive tree under `items`. Each entry is
     * one of:
     *   - kind=group:    has `children` (locations + decks + nested groups)
     *   - kind=location: a regular drawer/binder
     *   - kind=deck:     a deck shadow (Location with role='deck'), carries
     *                    deck-specific metadata (format, commander, ...)
     *
     * Children of a group are ordered by their shared `sort_order`.
     */
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        // card_count per location — one query, then index by location_id
        $cardCounts = CollectionEntry::query()
            ->selectRaw('location_id, COUNT(*) as c')
            ->where('user_id', $userId)
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->pluck('c', 'location_id');

        $locations = Location::query()
            ->where('user_id', $userId)
            ->sidebarVisible()
            ->with(['deck.commander1:scryfall_id,name,image_small'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $groups = LocationGroup::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Pre-compute deck entry counts and active illegalities for shadow rows.
        $deckIds = $locations
            ->where('role', Location::ROLE_DECK)
            ->pluck('deck_id')
            ->filter()
            ->all();

        $entryCounts = $deckIds === []
            ? collect()
            : DeckEntry::query()
                ->whereIn('deck_id', $deckIds)
                ->where('zone', 'main')
                ->select('deck_id', DB::raw('SUM(quantity) AS total'))
                ->groupBy('deck_id')
                ->pluck('total', 'deck_id');

        $ignoredByDeck = $deckIds === []
            ? collect()
            : DeckIgnoredIllegality::query()
                ->whereIn('deck_id', $deckIds)
                ->get()
                ->groupBy('deck_id');

        $presentLocation = function (Location $l) use ($cardCounts, $entryCounts, $ignoredByDeck): array {
            if ($l->role === Location::ROLE_DECK && $l->deck) {
                $deck = $l->deck;
                $illegalities = $this->legality->check($deck);
                $ignored = $ignoredByDeck->get($deck->id, collect());
                $active = 0;
                foreach ($illegalities as $ill) {
                    $match = false;
                    foreach ($ignored as $row) {
                        if ($row->illegality_type === $ill['type']
                            && $row->scryfall_id_1 === $ill['scryfall_id_1']
                            && $row->scryfall_id_2 === $ill['scryfall_id_2']
                            && $row->oracle_id     === $ill['oracle_id']) {
                            $match = true;
                            break;
                        }
                    }
                    if (! $match) {
                        $active++;
                    }
                }

                return [
                    'kind'             => 'deck',
                    'id'               => $l->id,
                    'group_id'         => $l->group_id,
                    'sort_order'       => $l->sort_order,
                    'name'             => $deck->name,
                    'deck_id'          => $deck->id,
                    'format'           => $deck->format,
                    'color_identity'   => $deck->color_identity,
                    'entry_count'      => (int) ($entryCounts[$deck->id] ?? 0),
                    'illegality_count' => $active,
                    'commander1'       => $deck->commander1 ? [
                        'name'        => $deck->commander1->name,
                        'image_small' => $deck->commander1->image_small,
                    ] : null,
                ];
            }

            return [
                'kind'        => 'location',
                'id'          => $l->id,
                'group_id'    => $l->group_id,
                'type'        => $l->type,
                'name'        => $l->name,
                'set_codes'   => $l->set_codes,
                'description' => $l->description,
                'sort_order'  => $l->sort_order,
                'card_count'  => (int) ($cardCounts[$l->id] ?? 0),
            ];
        };

        $locationsByGroup = $locations->groupBy(fn (Location $l) => $l->group_id ?? 0);
        $groupsByParent   = $groups->groupBy(fn (LocationGroup $g) => $g->parent_group_id ?? 0);

        $buildGroup = function (LocationGroup $g) use (&$buildGroup, $locationsByGroup, $groupsByParent, $presentLocation): array {
            $childLocations = $locationsByGroup->get($g->id, collect())->map($presentLocation);
            $childGroups = $groupsByParent->get($g->id, collect())->map(fn (LocationGroup $cg) => $buildGroup($cg));

            $children = $childLocations
                ->concat($childGroups)
                ->sortBy('sort_order')
                ->values()
                ->all();

            return [
                'kind'            => 'group',
                'id'              => $g->id,
                'name'            => $g->name,
                'parent_group_id' => $g->parent_group_id,
                'sort_order'      => $g->sort_order,
                'children'        => $children,
            ];
        };

        $topGroups    = $groupsByParent->get(0, collect())->map(fn (LocationGroup $g) => $buildGroup($g));
        $topLocations = $locationsByGroup->get(0, collect())->map($presentLocation);

        $items = $topGroups
            ->concat($topLocations)
            ->sortBy('sort_order')
            ->values()
            ->all();

        $total = CollectionEntry::where('user_id', $userId)->count();

        return response()->json([
            'items'       => $items,
            'total_count' => $total,
            'review'      => $this->sidebarReview($userId),
        ]);
    }

    /**
     * Review-queue summary for the sidebar. Returns NULL when nothing is
     * flagged for review — the row simply doesn't render.
     *
     * @return array{card_count: int}|null
     */
    private function sidebarReview(int $userId): ?array
    {
        $count = CollectionEntry::query()
            ->where('user_id', $userId)
            ->whereNotNull('review_reason')
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'card_count' => $count,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'parent_group_id' => 'nullable|integer',
        ]);

        $parentId = $data['parent_group_id'] ?? null;
        if ($parentId !== null) {
            $owned = LocationGroup::where('id', $parentId)
                ->where('user_id', $userId)
                ->exists();
            abort_unless($owned, 422, 'Invalid parent group');
        }

        $sortOrder = $parentId === null
            ? LocationGroup::nextTopLevelSortOrder($userId)
            : LocationGroup::nextChildSortOrder($userId, $parentId);

        $group = LocationGroup::create([
            'user_id'         => $userId,
            'parent_group_id' => $parentId,
            'name'            => $data['name'],
            'sort_order'      => $sortOrder,
        ]);

        return response()->json($this->present($group), 201);
    }

    public function update(Request $request, LocationGroup $group): JsonResponse
    {
        abort_if($group->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:100',
            'parent_group_id' => 'sometimes|nullable|integer',
        ]);

        if (array_key_exists('parent_group_id', $data)) {
            $parentId = $data['parent_group_id'];
            if ($parentId !== null) {
                if ($parentId === $group->id) {
                    throw ValidationException::withMessages([
                        'parent_group_id' => 'A group cannot be its own parent.',
                    ]);
                }
                $owned = LocationGroup::where('id', $parentId)
                    ->where('user_id', $group->user_id)
                    ->exists();
                abort_unless($owned, 422, 'Invalid parent group');

                if (in_array($parentId, $group->descendantIds(), true)) {
                    throw ValidationException::withMessages([
                        'parent_group_id' => 'A group cannot be moved under one of its descendants.',
                    ]);
                }
            }
        }

        $group->update($data);

        return response()->json($this->present($group));
    }

    public function destroy(LocationGroup $group): Response
    {
        abort_if($group->user_id !== auth()->id(), 403);

        DB::transaction(function () use ($group) {
            // Promote child locations and child groups to top level rather
            // than orphaning or wiping them. The FK on parent_group_id is
            // already nullOnDelete so the children update is for child
            // locations only.
            Location::where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();
        });

        return response()->noContent();
    }

    /**
     * POST /api/location-groups/reorder
     *
     * Accepts the complete desired sidebar tree:
     *   {
     *     "items": [
     *       { "kind": "location", "id": 3 },
     *       { "kind": "group", "id": 1, "children": [
     *         { "kind": "location", "id": 5 },
     *         { "kind": "group", "id": 9, "children": [
     *           { "kind": "location", "id": 11 }
     *         ] },
     *         { "kind": "location", "id": 7 }
     *       ] },
     *       { "kind": "location", "id": 8 }
     *     ]
     *   }
     *
     * Each item's `sort_order` is its position in its enclosing array. Each
     * group item's `parent_group_id` is its enclosing group's id (or NULL at
     * the top level). Each location item's `group_id` is its enclosing
     * group's id (or NULL at the top level). Decks are addressed by their
     * shadow Location's id; their kind in the payload is "location".
     *
     * Verifies every group and location belongs to the auth user before any
     * writes. Wrapped in a transaction; cycles in the payload (a group
     * appearing inside its own subtree) are rejected with 422.
     */
    public function reorder(Request $request): Response
    {
        $data = $request->validate([
            // Top-level cap; the recursive walker below also enforces a
            // total-node cap so a deeply-nested payload can't blow up.
            'items'                          => 'present|array|max:500',
            'items.*'                        => 'array',
        ]);

        $userId = auth()->id();

        $groupIds    = [];
        $locationIds = [];
        // Total-node cap on the recursive walk: even with the array-level
        // max:500 above, a payload could still nest 500 children inside
        // each top-level item. Without this guard an attacker could
        // submit a tree with N^2 nodes that we'd walk (and ownership-
        // check) before any write happens. 1000 is well above the size
        // of any real-world sidebar.
        $remaining = 1000;
        $this->collectIds($data['items'], $groupIds, $locationIds, $remaining);
        if ($remaining < 0) {
            throw ValidationException::withMessages([
                'items' => 'Reorder payload too large.',
            ]);
        }

        // Reject any group_id appearing more than once in the payload —
        // that's the structural definition of a cycle.
        if (count($groupIds) !== count(array_unique($groupIds))) {
            throw ValidationException::withMessages([
                'items' => 'A group cannot appear more than once in the tree.',
            ]);
        }
        $locationIds = array_values(array_unique($locationIds));

        // Ownership check: any mismatched ID = 403, no partial writes.
        if ($groupIds !== []) {
            $ownedGroups = LocationGroup::where('user_id', $userId)
                ->whereIn('id', $groupIds)
                ->count();
            abort_if($ownedGroups !== count($groupIds), 403, 'Invalid group id');
        }
        if ($locationIds !== []) {
            $ownedLocs = Location::where('user_id', $userId)
                ->whereIn('id', $locationIds)
                ->count();
            abort_if($ownedLocs !== count($locationIds), 403, 'Invalid location id');
        }

        DB::transaction(function () use ($data) {
            $this->applyReorder($data['items'], null);
        });

        return response()->noContent();
    }

    /**
     * Recursively walk the reorder payload, writing parent_group_id /
     * group_id and sort_order for every group and location encountered.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function applyReorder(array $items, ?int $parentGroupId): void
    {
        foreach ($items as $idx => $item) {
            $kind = $item['kind'] ?? null;
            $id   = isset($item['id']) ? (int) $item['id'] : null;
            if ($id === null) {
                continue;
            }

            if ($kind === 'group') {
                LocationGroup::where('id', $id)->update([
                    'parent_group_id' => $parentGroupId,
                    'sort_order'      => $idx,
                ]);
                $children = $item['children'] ?? [];
                if (is_array($children)) {
                    $this->applyReorder($children, $id);
                }
            } elseif ($kind === 'location' || $kind === 'deck') {
                Location::where('id', $id)->update([
                    'group_id'   => $parentGroupId,
                    'sort_order' => $idx,
                ]);
            }
        }
    }

    /**
     * Walk the payload and accumulate every referenced group_id and
     * location_id for ownership/cycle checks.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>                  $groupIds
     * @param array<int, int>                  $locationIds
     * @param int                               $remaining  decremented per-node; when it drops
     *                                                     below zero the caller throws 422 to
     *                                                     reject the payload.
     */
    private function collectIds(array $items, array &$groupIds, array &$locationIds, int &$remaining): void
    {
        foreach ($items as $item) {
            if ($remaining-- < 0) {
                return;
            }
            $kind = $item['kind'] ?? null;
            $id   = isset($item['id']) ? (int) $item['id'] : null;
            if ($id === null) {
                continue;
            }

            if ($kind === 'group') {
                $groupIds[] = $id;
                $children = $item['children'] ?? [];
                if (is_array($children)) {
                    $this->collectIds($children, $groupIds, $locationIds, $remaining);
                }
            } elseif ($kind === 'location' || $kind === 'deck') {
                $locationIds[] = $id;
            }
        }
    }

    /** @return array<string, mixed> */
    private function present(LocationGroup $group): array
    {
        return [
            'kind'            => 'group',
            'id'              => $group->id,
            'name'            => $group->name,
            'parent_group_id' => $group->parent_group_id,
            'sort_order'      => $group->sort_order,
            'children'        => [],
        ];
    }
}
