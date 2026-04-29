<?php

namespace App\Observers;

use App\Models\Deck;
use App\Models\Location;

class DeckObserver
{
    /**
     * Each deck gets its own auto-managed Location row used as the physical
     * "this deck owns these copies" bucket. The FK on locations.deck_id
     * cascades on deck delete, so we only need to handle create/rename here.
     */
    public function created(Deck $deck): void
    {
        Location::create([
            'user_id' => $deck->user_id,
            'deck_id' => $deck->id,
            'role'    => Location::ROLE_DECK,
            // `type` is enum('drawer','binder') at the schema level; role='deck'
            // already identifies this row as the deck-location, so we pick
            // 'drawer' arbitrarily for the type slot.
            'type'    => 'drawer',
            'name'    => $this->locationName($deck->name),
        ]);
    }

    public function updated(Deck $deck): void
    {
        if (! $deck->wasChanged('name')) {
            return;
        }

        Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->update(['name' => $this->locationName($deck->name)]);
    }

    private function locationName(string $deckName): string
    {
        // Truncate so we never overflow locations.name (varchar 100). Match
        // the "Deck: " prefix the docblock on migration #2 documents.
        $prefix = 'Deck: ';
        $room = 100 - strlen($prefix);

        return $prefix . mb_substr($deckName, 0, $room);
    }
}
