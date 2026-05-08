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

        // Scryfall lists upcoming sets with a future released_at (e.g. Marvel,
        // Strixhaven are announced ahead of their street date). Clamp to today
        // so the hero only shows sets that have actually released.
        $today = now()->toDateString();

        $latestDate = MtgSet::query()
            ->whereNotNull('released_at')
            ->where('released_at', '<=', $today)
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

        // type_line moved off scryfall_cards to scryfall_oracles in #177
        // (drop_oracle_invariant_columns_from_scryfall_cards). Join the oracle
        // table to keep the "exclude lands" filter working. select() is needed
        // so the join doesn't leak oracle columns into the model's attributes
        // (which would shadow the printing-level columns we actually want).
        $card = ScryfallCard::query()
            ->join('scryfall_oracles', 'scryfall_cards.oracle_id', '=', 'scryfall_oracles.oracle_id')
            ->whereIn('scryfall_cards.set_code', $setCodes)
            ->whereNotNull('scryfall_cards.image_normal')
            ->where(function ($q) {
                $q->whereNull('scryfall_oracles.type_line')
                  ->orWhere('scryfall_oracles.type_line', 'not like', '%Land%');
            })
            ->select('scryfall_cards.*')
            ->inRandomOrder()
            ->first();

        if (! $card) {
            return response()->json(null);
        }

        // Oracle-invariant fields (type_line, mana_cost, oracle_text,
        // power, toughness) are exposed via accessors on ScryfallCard that
        // proxy to the auto-loaded oracle relation — they look like native
        // columns to the caller.
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
