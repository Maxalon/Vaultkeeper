<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\LocationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LocationGroupController extends Controller
{
    /**
     * GET /api/location-groups
     *
     * Returns the full sidebar structure as a single `items` array that
     * interleaves groups and top-level (ungrouped) locations, sorted by
     * their shared top-level `sort_order`. Groups carry their nested
     * locations in `locations`, sorted by the nested `sort_order`.
     */
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        // card_count per location — one query, then index by location_id
        $counts = CollectionEntry::query()
            ->selectRaw('location_id, COUNT(*) as c')
            ->where('user_id', $userId)
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->pluck('c', 'location_id');

        $locations = Location::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $presentLoc = fn (Location $l): array => [
            'kind'        => 'location',
            'id'          => $l->id,
            'group_id'    => $l->group_id,
            'type'        => $l->type,
            'name'        => $l->name,
            'set_codes'   => $l->set_codes,
            'description' => $l->description,
            'sort_order'  => $l->sort_order,
            'card_count'  => (int) ($counts[$l->id] ?? 0),
        ];

        $groups = LocationGroup::query()
            ->where('user_id', $userId)
            ->get()
            ->map(fn (LocationGroup $g) => [
                'kind'       => 'group',
                'id'         => $g->id,
                'name'       => $g->name,
                'sort_order' => $g->sort_order,
                'locations'  => $locations
                    ->where('group_id', $g->id)
                    ->values()
                    ->map($presentLoc)
                    ->all(),
            ]);

        $topLocations = $locations
            ->whereNull('group_id')
            ->values()
            ->map($presentLoc);

        // Interleave and sort by the shared top-level sort_order. PHP's sortBy
        // is stable, so ties break by original insertion order (groups first).
        $items = $groups
            ->concat($topLocations)
            ->sortBy('sort_order')
            ->values()
            ->all();

        $total = CollectionEntry::where('user_id', $userId)->count();

        return response()->json([
            'items'       => $items,
            'total_count' => $total,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $userId = auth()->id();

        $group = LocationGroup::create([
            'user_id'    => $userId,
            'name'       => $data['name'],
            'sort_order' => LocationGroup::nextTopLevelSortOrder($userId),
        ]);

        return response()->json($this->present($group), 201);
    }

    public function update(Request $request, LocationGroup $group): JsonResponse
    {
        abort_if($group->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $group->update($data);

        return response()->json($this->present($group));
    }

    public function destroy(LocationGroup $group): Response
    {
        abort_if($group->user_id !== auth()->id(), 403);

        DB::transaction(function () use ($group) {
            Location::where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();
        });

        return response()->noContent();
    }

    /**
     * POST /api/location-groups/reorder
     *
     * Accepts the complete desired state as a single interleaved list:
     *   {
     *     "items": [
     *       { "kind": "location", "id": 3 },
     *       { "kind": "group", "id": 1, "location_ids": [5, 7] },
     *       { "kind": "location", "id": 8 },
     *       { "kind": "group", "id": 2, "location_ids": [] }
     *     ]
     *   }
     *
     * Top-level sort_order is derived from each item's position in the array;
     * groups and top-level locations share the same ordering space. Nested
     * location sort_order is derived from position within a group's
     * location_ids array. Verifies every group and location belongs to the
     * auth user before applying any writes.
     */
    public function reorder(Request $request): Response
    {
        $data = $request->validate([
            'items'                   => 'present|array',
            'items.*.kind'            => 'required|in:group,location',
            'items.*.id'              => 'required|integer',
            'items.*.location_ids'    => 'sometimes|array',
            'items.*.location_ids.*'  => 'integer',
        ]);

        $userId = auth()->id();

        $groupIds = [];
        $locationIds = [];
        foreach ($data['items'] as $item) {
            if ($item['kind'] === 'group') {
                $groupIds[] = $item['id'];
                foreach (($item['location_ids'] ?? []) as $lId) {
                    $locationIds[] = $lId;
                }
            } else {
                $locationIds[] = $item['id'];
            }
        }
        $groupIds = array_values(array_unique($groupIds));
        $locationIds = array_values(array_unique($locationIds));

        // Ownership check: any mismatched ID = 403, no partial writes.
        if (! empty($groupIds)) {
            $ownedGroups = LocationGroup::where('user_id', $userId)
                ->whereIn('id', $groupIds)
                ->count();
            abort_if($ownedGroups !== count($groupIds), 403, 'Invalid group id');
        }
        if (! empty($locationIds)) {
            $ownedLocs = Location::where('user_id', $userId)
                ->whereIn('id', $locationIds)
                ->count();
            abort_if($ownedLocs !== count($locationIds), 403, 'Invalid location id');
        }

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $idx => $item) {
                if ($item['kind'] === 'group') {
                    LocationGroup::where('id', $item['id'])
                        ->update(['sort_order' => $idx]);
                    foreach (($item['location_ids'] ?? []) as $lIdx => $locId) {
                        Location::where('id', $locId)->update([
                            'group_id'   => $item['id'],
                            'sort_order' => $lIdx,
                        ]);
                    }
                } else {
                    Location::where('id', $item['id'])->update([
                        'group_id'   => null,
                        'sort_order' => $idx,
                    ]);
                }
            }
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(LocationGroup $group): array
    {
        return [
            'kind'       => 'group',
            'id'         => $group->id,
            'name'       => $group->name,
            'sort_order' => $group->sort_order,
            'locations'  => [],
        ];
    }
}
