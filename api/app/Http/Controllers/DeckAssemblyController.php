<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Services\AssembleIntent;
use App\Services\DeckAssemblyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controllers for the assemble / unassemble flows. Both endpoints sit
 * under POST /api/decks/{deck}/(un)assemble — they're not idempotent
 * GETs (they write CEs and flip flags) so POST is the right verb.
 */
class DeckAssemblyController extends Controller
{
    public function __construct(private readonly DeckAssemblyService $assembly) {}

    /**
     * POST /api/decks/{deck}/assemble
     *
     * Body shape (locked decision 7 — payload validated here, the modal
     * state lives client-side and the SPA assembles after persisting the
     * deck for import flows):
     *   {
     *     "all": bool,
     *     "sections": ["main","side","maybe"],   // ignored when all=true
     *     "excludes": [
     *       {"scryfall_id": "<uuid>", "zone": "main", "qty": 2}, …
     *     ]
     *   }
     */
    public function assemble(Request $request, Deck $deck): JsonResponse
    {
        abort_if($deck->user_id !== auth()->id(), 403);

        $data = $request->validate([
            'all'                    => 'sometimes|boolean',
            // sections is a fixed enum domain — 3 entries — so a small cap
            // is the right shape; excludes scales with deck size, capped
            // at 500 (more than any sane deck) so an attacker can't force
            // 10K+ exists() lookups on scryfall_cards before the real work
            // begins.
            'sections'               => 'sometimes|array|max:3',
            'sections.*'             => 'in:main,side,maybe',
            'excludes'               => 'sometimes|array|max:500',
            'excludes.*.scryfall_id' => 'required_with:excludes|uuid|exists:scryfall_cards,scryfall_id',
            'excludes.*.zone'        => 'required_with:excludes|in:main,side,maybe',
            'excludes.*.qty'         => 'required_with:excludes|integer|min:1|max:1000',
        ]);

        $intent = AssembleIntent::fromArray($data);
        $result = $this->assembly->assemble($deck, $intent);

        return response()->json($result);
    }

    /**
     * POST /api/decks/{deck}/unassemble
     *
     * Tears down the deck-location: every CE in it is sent to the review
     * queue with reason `no_location`, and every entry's
     * `physical_copy_id` is cleared. No CE is deleted. No body — the
     * action is parameterless.
     */
    public function unassemble(Deck $deck): JsonResponse
    {
        abort_if($deck->user_id !== auth()->id(), 403);

        $result = $this->assembly->unassemble($deck);

        return response()->json($result);
    }
}
