<?php

namespace App\Services;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PendingRelocationService
{
    /**
     * Returns the user's singleton `pending_relocation` Location, creating it
     * on first call. Postgres enforces uniqueness via a partial unique index
     * (see migration 2026_04_28_000002); on MySQL we rely on this method
     * being the only writer of role='pending_relocation' rows.
     *
     * Wrapped in a transaction so a concurrent first-call from two requests
     * doesn't race to create duplicates.
     */
    public function ensureLocation(User $user): Location
    {
        return DB::transaction(function () use ($user) {
            $existing = Location::query()
                ->where('user_id', $user->id)
                ->where('role', Location::ROLE_PENDING_RELOCATION)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return Location::create([
                'user_id' => $user->id,
                'role'    => Location::ROLE_PENDING_RELOCATION,
                'type'    => 'drawer',
                'name'    => 'Pending Relocation',
            ]);
        });
    }

    /**
     * Move the given collection_entry into its owner's pending-relocation
     * bucket, stamping the source deck so the UI can label "from <deck>".
     * If `$deckBeingDeleted` is true, also flips source_deck_deleted on this
     * row — used by the Deck deleting observer so the snapshot stays the
     * canonical label after the FK nulls source_deck_id out.
     */
    public function moveCopyToPending(
        CollectionEntry $copy,
        Deck $sourceDeck,
        bool $deckBeingDeleted = false,
    ): void {
        $pending = $this->ensureLocation($copy->user);

        $copy->forceFill([
            'location_id'               => $pending->id,
            'source_deck_id'            => $sourceDeck->id,
            'source_deck_name_snapshot' => mb_substr($sourceDeck->name, 0, 100),
            'source_deck_deleted'       => $deckBeingDeleted,
        ])->save();
    }
}
