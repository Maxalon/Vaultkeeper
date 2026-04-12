<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\LocationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    /**
     * GET /api/locations — list user's locations with card_count and total.
     */
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        $locations = Location::query()
            ->where('user_id', $userId)
            ->withCount(['entries as card_count' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $l) => [
                'id'          => $l->id,
                'type'        => $l->type,
                'name'        => $l->name,
                'set_codes'   => $l->set_codes,
                'description' => $l->description,
                'card_count'  => (int) $l->card_count,
            ]);

        $total = CollectionEntry::where('user_id', $userId)->count();

        return response()->json([
            'locations'   => $locations->all(),
            'total_count' => $total,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'        => 'required|in:drawer,binder,deck',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $userId = auth()->id();

        $location = Location::create([
            ...$data,
            'user_id'    => $userId,
            'sort_order' => LocationGroup::nextTopLevelSortOrder($userId),
        ]);

        return response()->json($this->present($location, 0), 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        abort_if($location->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'type'        => 'required|in:drawer,binder,deck',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $location->update($data);

        $count = CollectionEntry::where('user_id', auth()->id())
            ->where('location_id', $location->id)
            ->count();

        return response()->json($this->present($location, $count));
    }

    public function destroy(Location $location): Response
    {
        abort_if($location->user_id !== auth()->id(), 403);

        // Detach entries before deleting so the user doesn't lose collection
        // rows just because they removed a drawer.
        DB::transaction(function () use ($location) {
            CollectionEntry::where('location_id', $location->id)
                ->update(['location_id' => null]);

            $location->delete();
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Location $location, int $cardCount): array
    {
        return [
            'id'          => $location->id,
            'type'        => $location->type,
            'name'        => $location->name,
            'set_codes'   => $location->set_codes,
            'description' => $location->description,
            'card_count'  => $cardCount,
        ];
    }
}
