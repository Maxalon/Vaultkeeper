<?php

namespace App\Http\Controllers;

use App\Models\MtgSet;
use App\Models\ScryfallCard;
use App\Services\BulkSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        // Exclude the same set_types BulkSyncService hard-skips during
        // catalog ingestion (art_series, funny, memorabilia, minigame,
        // token). If one of those is the most recent release, no cards
        // are ingested for it and the picker returns null instead of
        // falling through to the previous date. Also drop Secret Lair —
        // SLD releases continuously and would dominate "newest release".
        $excludedTypes = BulkSyncService::INELIGIBLE_SET_TYPES;
        $excludedCodes = ['sld'];

        // Scryfall lists upcoming sets with a future released_at (e.g. Marvel,
        // Strixhaven are announced ahead of their street date). Clamp to today
        // so the hero only shows sets that have actually released.
        $cursor = now()->toDateString();

        // Walk back through release dates if the newest qualifying set
        // group still yields no eligible cards (e.g. a recent supplemental
        // product with no English images ingested yet). Capped at 10
        // dates so a fully empty catalogue can't loop forever.
        for ($i = 0; $i < 10; $i++) {
            $latestDate = MtgSet::query()
                ->whereNotNull('released_at')
                ->where('released_at', '<=', $cursor)
                ->whereNotIn('set_type', $excludedTypes)
                ->whereNotIn('code', $excludedCodes)
                ->max('released_at');

            if (! $latestDate) {
                break;
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
                ->where('scryfall_cards.language', 'en')
                ->whereNotNull('scryfall_cards.image_normal')
                ->where(function ($q) {
                    $q->whereNull('scryfall_oracles.type_line')
                        ->orWhere('scryfall_oracles.type_line', 'not like', '%Land%');
                })
                ->select('scryfall_cards.*')
                ->inRandomOrder()
                ->first();

            if ($card) {
                // Oracle-invariant fields (type_line, mana_cost, oracle_text,
                // power, toughness) are exposed via accessors on ScryfallCard that
                // proxy to the auto-loaded oracle relation — they look like native
                // columns to the caller.
                return response()->json([
                    'name' => $card->name,
                    'type_line' => $card->type_line,
                    'mana_cost' => $card->mana_cost,
                    'oracle_text' => $card->oracle_text,
                    'image_normal' => $card->image_normal,
                    'image_large' => $card->image_large,
                    'set_code' => $card->set_code,
                    'collector_number' => $card->collector_number,
                    'power' => $card->power,
                    'toughness' => $card->toughness,
                ]);
            }

            $cursor = Carbon::parse($latestDate)->subDay()->toDateString();
        }

        return response()->json(null);
    }
}
