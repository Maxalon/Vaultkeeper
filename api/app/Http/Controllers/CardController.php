<?php

namespace App\Http\Controllers;

use App\Models\MtgSet;
use App\Models\ScryfallCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function index() {}
    public function store(Request $request) {}
    public function show(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}

    /**
     * GET /api/cards/featured (public — used by the login hero).
     *
     * Picks one random non-land card from the most recent mainline release.
     * Scryfall ships companion sets (bonus sheets, commander decks, etc.)
     * on the same day as the main expansion, so "most recent release" is
     * defined as all non-token, non-SLD sets sharing the latest released_at
     * date — e.g. Secrets of Strixhaven + its Mystical Archive count as one.
     * Returns null when no suitable card exists so the frontend can render
     * a built-in fallback.
     */
    public function featured(): JsonResponse
    {
        // Token sets (TSOS, TSOA, …) and Secret Lair drops shouldn't compete
        // for "newest release": tokens aren't real cards, and SLD releases
        // continuously and would win almost every time.
        $excludedTypes = ['token'];
        $excludedCodes = ['sld'];

        $latestDate = MtgSet::query()
            ->whereNotNull('released_at')
            ->whereNotIn('set_type', $excludedTypes)
            ->whereNotIn('code', $excludedCodes)
            ->max('released_at');

        if (! $latestDate) {
            return response()->json(null);
        }

        $setCodes = MtgSet::query()
            ->where('released_at', $latestDate)
            ->whereNotIn('set_type', $excludedTypes)
            ->whereNotIn('code', $excludedCodes)
            ->pluck('code')
            ->all();

        $card = ScryfallCard::query()
            ->whereIn('set_code', $setCodes)
            ->where(function ($q) {
                $q->whereNull('type_line')->orWhere('type_line', 'not like', '%Land%');
            })
            ->whereNotNull('image_normal')
            ->inRandomOrder()
            ->first();

        if (! $card) {
            return response()->json(null);
        }

        return response()->json([
            'name'             => $card->name,
            'type_line'        => $card->type_line,
            'mana_cost'        => $card->mana_cost,
            'oracle_text'      => $card->oracle_text,
            'image_normal'     => $card->image_normal,
            'image_large'      => $card->image_large,
            'set_code'         => $card->set_code,
            'collector_number' => $card->collector_number,
            'power'            => $card->power,
            'toughness'        => $card->toughness,
        ]);
    }
}
