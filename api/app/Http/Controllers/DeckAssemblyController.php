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
            'sections'               => 'sometimes|array',
            'sections.*'             => 'in:main,side,maybe',
            'excludes'               => 'sometimes|array',
            'excludes.*.scryfall_id' => 'required_with:excludes|uuid|exists:scryfall_cards,scryfall_id',
            'excludes.*.zone'        => 'required_with:excludes|in:main,side,maybe',
            'excludes.*.qty'         => 'required_with:excludes|integer|min:1',
        ]);

        $intent = AssembleIntent::fromArray($data);
        $result = $this->assembly->assemble($deck, $intent);

        return response()->json($result);
    }

    /**
     * POST /api/decks/{deck}/unassemble
     *
     * Tears down the deck-location: deletes system-created CEs, queues
     * user-touched ones to pending, clears every entry's
     * `physical_copy_id`. No body — the action is parameterless.
     */
    public function unassemble(Deck $deck): JsonResponse
    {
        abort_if($deck->user_id !== auth()->id(), 403);

        $result = $this->assembly->unassemble($deck);

        return response()->json($result);
    }
}
