<?php

namespace App\Http\Controllers;

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
     * Picks one random non-land card from the most recently released set
     * in the scryfall_cards table. Returns null when the table is empty
     * so the frontend can render a built-in fallback.
     */
    public function featured(): JsonResponse
    {
        $newestSet = ScryfallCard::query()
            ->select('set_code')
            ->groupBy('set_code')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(1)
            ->value('set_code');

        if (! $newestSet) {
            return response()->json(null);
        }

        $card = ScryfallCard::query()
            ->where('set_code', $newestSet)
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
