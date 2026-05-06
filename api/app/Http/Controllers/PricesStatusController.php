<?php

namespace App\Http\Controllers;

use App\Models\SyncState;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/prices/status — small status payload for the frontend's
 * "prices last updated" hint. Reads two SyncState keys written by
 * ScryfallSyncPrices:
 *   - prices_last_synced_at   ISO timestamp of the last successful run
 *   - prices_last_manifest_at Scryfall manifest's own updated_at value
 */
class PricesStatusController extends Controller
{
    public function show(): JsonResponse
    {
        $rows = SyncState::query()
            ->whereIn('key', ['prices_last_synced_at', 'prices_last_manifest_at'])
            ->pluck('value', 'key');

        return response()->json([
            'last_synced_at'   => $rows['prices_last_synced_at']   ?? null,
            'last_manifest_at' => $rows['prices_last_manifest_at'] ?? null,
            'source'           => 'scryfall-cardmarket',
            'currency'         => 'EUR',
            'notes'            => 'Estimated EUR prices via Scryfall (Cardmarket trend).',
        ]);
    }
}
