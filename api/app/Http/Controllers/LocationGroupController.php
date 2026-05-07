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
     * POST /api/location-groups/move
     *
     * Atomic single-item drag-and-drop. The frontend issues one of these per
     * drop; the request body identifies the moved item, its new parent, and
     * the 0-based position within that parent's merged sibling list (groups
     * + sidebar-visible locations interleaved by sort_order).
     *
     * Body:
     *   {
     *     "kind":      "location" | "deck" | "group",
     *     "id":         int,
     *     "parent_id":  int | null,    // group id; null = top level
     *     "position":   int >= 0       // index within destination siblings
     *   }
     *
     * Compared to the previous "send the whole tree" reorder endpoint, this
     * shape has no possible cross-request races: each drop is one row's
     * parent change plus a small per-parent renumber, all inside a single
     * transaction. Concurrent moves of different items in different parents
     * don't conflict at all.
     */
    public function move(Request $request): Response
    {
        $data = $request->validate([
            'kind'      => 'required|in:location,deck,group',
            'id'        => 'required|integer',
            'parent_id' => 'nullable|integer',
            'position'  => 'required|integer|min:0',
        ]);

        $userId       = auth()->id();
        $kind         = $data['kind'];
        $id           = (int) $data['id'];
        $destParentId = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        $position     = (int) $data['position'];

        if ($destParentId !== null) {
            $owned = LocationGroup::where('id', $destParentId)
                ->where('user_id', $userId)
                ->exists();
            abort_unless($owned, 422, 'Invalid parent group');
        }

        if ($kind === 'group') {
            $group = LocationGroup::where('id', $id)
                ->where('user_id', $userId)
                ->first();
            abort_unless($group !== null, 403);

            if ($destParentId !== null) {
                if ($destParentId === $group->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A group cannot be its own parent.',
                    ]);
                }
                if (in_array($destParentId, $group->descendantIds(), true)) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A group cannot be moved under one of its descendants.',
                    ]);
                }
            }

            $sourceParentId = $group->parent_group_id !== null ? (int) $group->parent_group_id : null;
        } else {
            $location = Location::where('id', $id)
                ->where('user_id', $userId)
                ->first();
            abort_unless($location !== null, 403);
            abort_unless(
                in_array($location->role, [Location::ROLE_USER, Location::ROLE_DECK], true),
                422,
                'This location cannot be moved in the sidebar.'
            );

            $sourceParentId = $location->group_id !== null ? (int) $location->group_id : null;
        }

        DB::transaction(function () use ($kind, $id, $destParentId, $sourceParentId, $position, $userId) {
            // Reparent the moved row first so it shows up in the destination
            // sibling list during renumbering.
            if ($kind === 'group') {
                LocationGroup::where('id', $id)->update(['parent_group_id' => $destParentId]);
            } else {
                Location::where('id', $id)->update(['group_id' => $destParentId]);
            }

            // Renumber destination siblings with the moved item spliced in
            // at the requested position. Position is clamped to the actual
            // sibling count so a stale frontend can't push it out of range.
            $siblings = $this->siblingsOf($userId, $destParentId);
            $others   = array_values(array_filter(
                $siblings,
                fn (array $r) => ! ($r['kind'] === $kind && $r['id'] === $id)
            ));
            $clamped  = max(0, min($position, count($others)));
            array_splice($others, $clamped, 0, [['kind' => $kind, 'id' => $id]]);
            $this->writeOrder($others);

            // Close the gap left in the source parent. Skipped when the
            // drop landed inside the same container — siblingsOf already
            // included the moved item there.
            if ($sourceParentId !== $destParentId) {
                $this->writeOrder($this->siblingsOf($userId, $sourceParentId));
            }
        });

        return response()->noContent();
    }

    /**
     * Sibling list of a parent container — child groups + sidebar-visible
     * child locations — merged and sorted by (sort_order, name) to match
     * exactly what `index()` renders. Each row is a small array of
     * `{kind, id}`; that's all the renumber loop needs.
     *
     * @return array<int, array{kind: string, id: int}>
     */
    private function siblingsOf(int $userId, ?int $parentId): array
    {
        $rows = [];

        $groups = LocationGroup::query()
            ->where('user_id', $userId)
            ->when(
                $parentId === null,
                fn ($q) => $q->whereNull('parent_group_id'),
                fn ($q) => $q->where('parent_group_id', $parentId)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'sort_order', 'name']);
        foreach ($groups as $g) {
            $rows[] = [
                'kind'        => 'group',
                'id'          => (int) $g->id,
                '_sort_order' => (int) $g->sort_order,
                '_name'       => (string) $g->name,
            ];
        }

        $locations = Location::query()
            ->where('user_id', $userId)
            ->sidebarVisible()
            ->when(
                $parentId === null,
                fn ($q) => $q->whereNull('group_id'),
                fn ($q) => $q->where('group_id', $parentId)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'sort_order', 'name', 'role']);
        foreach ($locations as $l) {
            $rows[] = [
                'kind'        => $l->role === Location::ROLE_DECK ? 'deck' : 'location',
                'id'          => (int) $l->id,
                '_sort_order' => (int) $l->sort_order,
                '_name'       => (string) $l->name,
            ];
        }

        usort($rows, function (array $a, array $b) {
            return $a['_sort_order'] <=> $b['_sort_order']
                ?: strcmp($a['_name'], $b['_name']);
        });

        return array_map(
            fn (array $r) => ['kind' => $r['kind'], 'id' => $r['id']],
            $rows
        );
    }

    /**
     * Write `sort_order = idx` for every row in `$rows`, in order. Rows
     * with kind=group hit `location_groups`; kind=location|deck hit
     * `locations` (deck shadows are just a Location with role='deck').
     *
     * @param array<int, array{kind: string, id: int}> $rows
     */
    private function writeOrder(array $rows): void
    {
        foreach ($rows as $idx => $row) {
            if ($row['kind'] === 'group') {
                LocationGroup::where('id', $row['id'])->update(['sort_order' => $idx]);
            } else {
                Location::where('id', $row['id'])->update(['sort_order' => $idx]);
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
