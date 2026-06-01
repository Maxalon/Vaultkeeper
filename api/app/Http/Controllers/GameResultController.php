<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameResultController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'winner_deck_id'    => 'nullable|integer',
            'loser_deck_ids'    => 'present|array',
            'loser_deck_ids.*'  => 'integer',
        ]);

        $userId = auth()->id();
        $winnerId = $data['winner_deck_id'] ?? null;
        $loserIds = collect($data['loser_deck_ids'])->unique()->values()->all();

        // Collect all deck ids that will be touched and verify ownership in one query.
        $allIds = collect($loserIds);
        if ($winnerId !== null) {
            $allIds->push($winnerId);
        }
        $allIds = $allIds->unique()->values()->all();

        if (count($allIds) > 0) {
            $owned = Deck::whereIn('id', $allIds)
                ->where('user_id', $userId)
                ->pluck('id')
                ->all();

            $unauthorized = array_diff($allIds, $owned);
            if (count($unauthorized) > 0) {
                abort(403, 'One or more decks do not belong to you.');
            }
        }

        DB::transaction(function () use ($winnerId, $loserIds) {
            if ($winnerId !== null) {
                Deck::where('id', $winnerId)->increment('wins');
                // Exclude winner from loser list even if caller sent it in both.
                $loserIds = array_filter($loserIds, fn ($id) => $id !== $winnerId);
            }
            if (count($loserIds) > 0) {
                Deck::whereIn('id', array_values($loserIds))->increment('losses');
            }
        });

        return response()->json(['ok' => true]);
    }
}
