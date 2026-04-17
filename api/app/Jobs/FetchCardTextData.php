<?php

namespace App\Jobs;

use App\Models\UserCard;
use App\Services\CardSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Async post-import sync. Loads any cards from the given scryfall_id list
 * that haven't been fetched yet and delegates to CardSyncService::syncMany,
 * which populates BOTH text and image fields via the same mapper used by
 * the lazy GET /api/collection path. Sharing the mapper means imported
 * cards land with full data, so the lazy fallback rarely fires.
 */
class FetchCardTextData implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string[]  $scryfallIds
     */
    public function __construct(public array $scryfallIds) {}

    public function handle(CardSyncService $sync): void
    {
        // image_small is the canary for "never synced" — set on every card
        // (front face for DFCs) the first time it goes through the mapper.
        $cards = UserCard::whereIn('scryfall_id', $this->scryfallIds)
            ->where(function ($q) {
                $q->whereNull('image_small')
                  ->orWhereNull('last_scryfall_sync');
            })
            ->get();

        if ($cards->isEmpty()) {
            return;
        }

        $sync->syncMany($cards);
    }
}
