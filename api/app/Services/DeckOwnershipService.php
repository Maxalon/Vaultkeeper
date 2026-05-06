<?php

namespace App\Services;

use App\Models\CollectionEntry;
use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * Ownership / pricing aggregates for collections and decks.
 *
 * Centralises the math that used to live as private helpers inside
 * DeckEntryController so the new totals endpoints can share it. All
 * monetary math is done in integer cents internally to avoid float
 * drift across hundreds of multiply-and-sum operations.
 */
class DeckOwnershipService
{
    /**
     * SUM(quantity) per scryfall_id for the user, across every collection
     * entry (deck-bound and otherwise). Same shape DeckEntryController
     * used for the per-row "owned_copies" badge.
     *
     * @param  array<int, string>  $scryfallIds
     * @return array<string, int>  scryfall_id => total owned
     */
    public function ownedCopiesMap(array $scryfallIds, int $userId): array
    {
        if (empty($scryfallIds)) return [];
        return CollectionEntry::query()
            ->select('scryfall_id', DB::raw('SUM(quantity) AS total'))
            ->where('user_id', $userId)
            ->whereIn('scryfall_id', $scryfallIds)
            ->groupBy('scryfall_id')
            ->pluck('total', 'scryfall_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Collection-level EUR totals for a user, optionally scoped to a
     * single location.
     *
     *   - both args default              → "all cards" view, every entry
     *   - $locationId = int              → entries pinned to that location
     *   - $unassignedOnly = true          → entries with NULL location_id
     *
     * Always excludes copies that live in a deck-role location, so the
     * total reflects what the user actually sees in the collection view.
     *
     * @return array{total: float, card_count: int, missing_price_count: int, generated_at: string}
     */
    public function totalsForCollection(int $userId, ?int $locationId = null, bool $unassignedOnly = false): array
    {
        $query = DB::table('collection_entries as ce')
            ->leftJoin('card_prices as cp', 'cp.scryfall_id', '=', 'ce.scryfall_id')
            ->where('ce.user_id', $userId)
            // Mirror the collection index: deck-bound copies are surfaced
            // by their deck_entry on the deck page; counting them in the
            // user-facing collection total would double-count.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('locations')
                    ->whereColumn('locations.id', 'ce.location_id')
                    ->where('locations.role', \App\Models\Location::ROLE_DECK);
            })
            ->select(
                'ce.quantity',
                'ce.foil',
                'ce.is_etched',
                'cp.eur',
                'cp.eur_foil',
                'cp.eur_etched',
            );

        if ($locationId !== null) {
            $query->where('ce.location_id', $locationId);
        } elseif ($unassignedOnly) {
            $query->whereNull('ce.location_id');
        }

        $rows = $query->get();

        $totalCents = 0;
        $cardCount = 0;
        $missingPrice = 0;

        foreach ($rows as $r) {
            $qty = (int) $r->quantity;
            $cardCount += $qty;
            $price = $this->pickPriceCents(
                (bool) $r->foil,
                (bool) $r->is_etched,
                $r->eur, $r->eur_foil, $r->eur_etched,
            );
            if ($price === null) {
                $missingPrice += $qty;
                continue;
            }
            $totalCents += $qty * $price;
        }

        return [
            'total'               => $this->fromCents($totalCents),
            'card_count'          => $cardCount,
            'missing_price_count' => $missingPrice,
            'generated_at'        => now()->toIso8601String(),
        ];
    }

    /**
     * Deck-level EUR totals: total / owned / missing, plus a count of
     * deck-entry copies whose printing has no EUR price.
     *
     * Definitions:
     *   - total          = Σ entry.quantity * unit_price
     *   - owned_total    = Σ min(entry.quantity, owned_copies) * unit_price
     *   - missing_total  = Σ max(0, entry.quantity - owned_copies) * unit_price
     *
     * `unit_price` is finish-aware: when the entry is bound to a physical
     * copy, the bound CE's foil/is_etched select the price column;
     * otherwise nonfoil. EUR-only — no currency parameter.
     *
     * @return array{total: float, owned_total: float, missing_total: float, missing_price_count: int, generated_at: string}
     */
    public function totalsForDeck(Deck $deck): array
    {
        $rows = DB::table('deck_entries as de')
            ->leftJoin('collection_entries as pc', 'pc.id', '=', 'de.physical_copy_id')
            ->leftJoin('card_prices as cp', 'cp.scryfall_id', '=', 'de.scryfall_id')
            ->where('de.deck_id', $deck->id)
            ->select(
                'de.scryfall_id',
                'de.quantity',
                'pc.foil as pc_foil',
                'pc.is_etched as pc_etched',
                'cp.eur',
                'cp.eur_foil',
                'cp.eur_etched',
            )
            ->get();

        $scryfallIds = $rows->pluck('scryfall_id')->unique()->all();
        $ownedMap = $this->ownedCopiesMap($scryfallIds, $deck->user_id);

        $totalCents = 0;
        $ownedCents = 0;
        $missingCents = 0;
        $missingPrice = 0;

        foreach ($rows as $r) {
            $qty = (int) $r->quantity;
            $ownedQty = min($qty, $ownedMap[$r->scryfall_id] ?? 0);
            $missingQty = max(0, $qty - $ownedQty);

            $price = $this->pickPriceCents(
                (bool) ($r->pc_foil ?? false),
                (bool) ($r->pc_etched ?? false),
                $r->eur, $r->eur_foil, $r->eur_etched,
            );
            if ($price === null) {
                $missingPrice += $qty;
                continue;
            }
            $totalCents   += $qty * $price;
            $ownedCents   += $ownedQty * $price;
            $missingCents += $missingQty * $price;
        }

        return [
            'total'               => $this->fromCents($totalCents),
            'owned_total'         => $this->fromCents($ownedCents),
            'missing_total'       => $this->fromCents($missingCents),
            'missing_price_count' => $missingPrice,
            'generated_at'        => now()->toIso8601String(),
        ];
    }

    /**
     * Pick the right EUR column for a (foil, is_etched) pair, returning
     * the price in integer cents or null when no price is available for
     * that finish.
     *
     * Etched falls back to foil when no etched price exists — the two
     * finishes share Cardmarket trends closely and a foil price is more
     * useful than nothing for an etched copy.
     */
    private function pickPriceCents(
        bool $foil,
        bool $etched,
        mixed $eur,
        mixed $eurFoil,
        mixed $eurEtched,
    ): ?int {
        $pick = $etched
            ? ($eurEtched ?? $eurFoil ?? null)
            : ($foil ? ($eurFoil ?? null) : ($eur ?? null));

        if ($pick === null || $pick === '') {
            return null;
        }
        return (int) round(((float) $pick) * 100);
    }

    private function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
