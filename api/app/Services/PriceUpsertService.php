<?php

namespace App\Services;

use App\Models\CardPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EUR price ingestion. Shared by:
 *
 *   - the weekly bulk sync (BulkSyncService::syncBulkCards), so prices
 *     stay fresh for cards that are themselves new in this sync
 *   - the daily price job (ScryfallSyncPricesJob), which pulls a fresh
 *     bulk file and rewrites every printing's snapshot
 *
 * Two-table layout:
 *   - card_prices         current EUR snapshot per scryfall_id
 *   - card_price_history  long-format, one row per (scryfall_id,
 *                         captured_on, finish) where the price changed
 *
 * EUR-only: Vaultkeeper's userbase is European and Cardmarket-centric;
 * TCGPlayer/USD adds no value here so the bulk feed's `prices.usd*`
 * fields are intentionally ignored.
 */
class PriceUpsertService
{
    private const UPSERT_CHUNK = 2000;

    /** Daily history retention. Older rows pruned at the end of each sync. */
    public const HISTORY_RETENTION_DAYS = 90;

    /**
     * Upsert a batch of price rows shaped by buildRow().
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function upsertRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            CardPrice::upsert(
                $chunk,
                ['scryfall_id'],
                ['eur', 'eur_foil', 'eur_etched', 'captured_on', 'updated_at'],
            );
        }
    }

    /**
     * Build a card_prices row from a single Scryfall bulk card payload.
     * Returns null when the printing exposes no EUR price at all (so we
     * don't insert empty rows for cards Cardmarket has never listed).
     *
     * @param  array<string, mixed>  $c   raw Scryfall bulk card object
     */
    public function buildRow(array $c, Carbon $now, ?Carbon $capturedOn = null): ?array
    {
        if (! isset($c['id'])) {
            return null;
        }
        $prices = (array) ($c['prices'] ?? []);
        $eur        = $this->normalisePrice($prices['eur']        ?? null);
        $eurFoil    = $this->normalisePrice($prices['eur_foil']   ?? null);
        $eurEtched  = $this->normalisePrice($prices['eur_etched'] ?? null);

        if ($eur === null && $eurFoil === null && $eurEtched === null) {
            return null;
        }

        return [
            'scryfall_id' => $c['id'],
            'eur'         => $eur,
            'eur_foil'    => $eurFoil,
            'eur_etched'  => $eurEtched,
            'captured_on' => ($capturedOn ?? $now)->toDateString(),
            'updated_at'  => $now,
        ];
    }

    /**
     * Append history rows for any (scryfall_id, finish) pair whose current
     * card_prices value differs from the most recent history entry. Set-
     * based — `INSERT IGNORE ... SELECT` against the union of the three
     * finish columns, with a NOT EXISTS clause that filters out triples
     * where today's price equals the latest historical price.
     *
     * On first run (history table empty), every non-NULL current price
     * becomes a baseline row; subsequent runs only insert deltas.
     *
     * Returns the number of rows inserted.
     */
    public function recordHistoryDeltas(): int
    {
        $finishes = [
            'nonfoil' => 'eur',
            'foil'    => 'eur_foil',
            'etched'  => 'eur_etched',
        ];

        $totalInserted = 0;

        foreach ($finishes as $finish => $column) {
            // Insert today's snapshot for this finish where the price is
            // non-NULL AND differs from the most recent prior history row
            // (or no prior row exists). INSERT IGNORE handles the unique
            // (scryfall_id, captured_on, finish) collision when the daily
            // job re-runs against the same captured_on.
            $sql = <<<SQL
                INSERT IGNORE INTO card_price_history
                    (scryfall_id, captured_on, finish, price)
                SELECT cp.scryfall_id, cp.captured_on, ?, cp.{$column}
                FROM card_prices cp
                WHERE cp.{$column} IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM card_price_history h
                      WHERE h.scryfall_id = cp.scryfall_id
                        AND h.finish = ?
                        AND h.captured_on = (
                            SELECT MAX(h2.captured_on)
                            FROM card_price_history h2
                            WHERE h2.scryfall_id = cp.scryfall_id
                              AND h2.finish = ?
                        )
                        AND h.price = cp.{$column}
                  )
            SQL;

            $totalInserted += DB::affectingStatement($sql, [$finish, $finish, $finish]);
        }

        return $totalInserted;
    }

    /**
     * Drop card_price_history rows older than the retention window. Run
     * at the tail of each daily sync so the table stays bounded.
     *
     * Returns the number of rows deleted.
     */
    public function pruneOldHistory(int $days = self::HISTORY_RETENTION_DAYS): int
    {
        $cutoff = now()->subDays($days)->toDateString();
        $deleted = (int) DB::table('card_price_history')
            ->where('captured_on', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            Log::info("PriceUpsertService::pruneOldHistory — deleted {$deleted} rows older than {$cutoff}");
        }
        return $deleted;
    }

    /**
     * Scryfall returns prices as strings ("1.23") or null. Treat empty /
     * non-numeric strings as null so we don't store "0.00" as a real
     * price for an unlisted finish.
     */
    private function normalisePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        // Cap to two decimals, matching the column.
        return number_format((float) $value, 2, '.', '');
    }
}
