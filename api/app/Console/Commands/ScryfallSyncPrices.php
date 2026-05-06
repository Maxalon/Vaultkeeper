<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\PriceUpsertService;
use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Daily EUR price sync. Pulls Scryfall's `default_cards` bulk file,
 * extracts only the `prices.eur*` fields, and writes them to
 * card_prices + card_price_history.
 *
 * Cardmarket's own API has been closed to new applicants since 2024 and
 * its bulk Price Guide File is deprecated, so Scryfall (which mirrors
 * Cardmarket trend prices in its bulk feed) is our pipeline. EUR-only
 * by design — TCGPlayer/USD adds nothing for the German userbase.
 *
 * Reuses the same memory profile as scryfall:sync-bulk because the
 * source file is the same ~700 MB JSON.
 */
class ScryfallSyncPrices extends Command
{
    protected $signature = 'scryfall:sync-prices';

    protected $description = 'Daily EUR price sync from Scryfall bulk data (Cardmarket trend prices).';

    public function handle(ScryfallService $scryfall, PriceUpsertService $prices): int
    {
        ini_set('memory_limit', '3500M');

        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && ($err['type'] & (E_ERROR | E_COMPILE_ERROR | E_CORE_ERROR)) !== 0
                && str_contains($err['message'], 'Allowed memory size')) {
                fwrite(STDERR, "\nPrice sync failed: out of memory decoding the Scryfall bulk JSON. "
                    . "Peak exceeded the 3.5 GB PHP limit — either the default_cards file grew, "
                    . "or switch to a streaming parser.\n");
            }
        });

        $this->info('Scryfall price sync starting…');

        try {
            $manifest = $scryfall->fetchBulkDataManifest();
            $entry = collect($manifest)->firstWhere('type', 'default_cards');
            if (! $entry || empty($entry['download_uri']) || empty($entry['updated_at'])) {
                throw new RuntimeException('Scryfall manifest missing default_cards entry');
            }

            $manifestUpdatedAt = (string) $entry['updated_at'];

            // Manifest-mtime check: skip the (expensive) download when
            // Scryfall hasn't refreshed the file since our last successful
            // run. The bulk file is rebuilt roughly daily, so this lets us
            // be safe to run more often than once a day without re-doing
            // hundreds of MB of HTTP for nothing.
            $lastManifest = SyncState::where('key', 'prices_last_manifest_at')->value('value');
            if ($lastManifest === $manifestUpdatedAt) {
                $this->line("    manifest unchanged ({$manifestUpdatedAt}); nothing to do");
                return self::SUCCESS;
            }

            $dir = config('scryfall.bulk_dir');
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $relPath = 'scryfall-bulk/prices-' . now()->format('Y-m-d-His') . '.json';
            $absPath = $dir . '/' . basename($relPath);

            $this->step('1/4  download', function () use ($scryfall, $entry, $relPath) {
                $ok = $scryfall->downloadToDisk((string) $entry['download_uri'], 'local', $relPath);
                if (! $ok) {
                    throw new RuntimeException('Failed to download Scryfall bulk file for price sync');
                }
            });

            $count = 0;
            $this->step('2/4  upsert', function () use ($absPath, $prices, &$count) {
                $raw = File::get($absPath);
                $cards = json_decode($raw, true);
                unset($raw);
                if (! is_array($cards)) {
                    throw new RuntimeException('Failed to decode bulk JSON for price sync');
                }

                $now = now();
                $batch = [];
                foreach ($cards as $card) {
                    $row = $prices->buildRow($card, $now);
                    if ($row === null) {
                        continue;
                    }
                    $batch[] = $row;
                    if (count($batch) >= 2000) {
                        $prices->upsertRows($batch);
                        $count += count($batch);
                        $batch = [];
                    }
                }
                if ($batch) {
                    $prices->upsertRows($batch);
                    $count += count($batch);
                }

                unset($cards);
                File::delete($absPath);
            });

            $inserted = 0;
            $this->step('3/4  recordHistoryDeltas', function () use ($prices, &$inserted) {
                $inserted = $prices->recordHistoryDeltas();
                $this->line("    delta rows inserted: {$inserted}");
            });

            $deleted = 0;
            $this->step('4/4  pruneOldHistory', function () use ($prices, &$deleted) {
                $deleted = $prices->pruneOldHistory();
                $this->line("    pruned: {$deleted}");
            });

            // Stamp success — both fields, in one place. last_synced_at is
            // a wall-clock timestamp for the UI's "updated X ago" hint;
            // last_manifest_at is the Scryfall manifest's own updated_at,
            // used by the early-exit check above.
            SyncState::updateOrCreate(
                ['key' => 'prices_last_manifest_at'],
                ['value' => $manifestUpdatedAt],
            );
            SyncState::updateOrCreate(
                ['key' => 'prices_last_synced_at'],
                ['value' => now()->toIso8601String()],
            );

            Log::info("ScryfallSyncPrices — upserted {$count} rows, "
                . "inserted {$inserted} history deltas, pruned {$deleted}");
        } catch (Throwable $e) {
            $this->error('Price sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Scryfall price sync complete.');
        return self::SUCCESS;
    }

    private function step(string $label, callable $fn): void
    {
        $start = microtime(true);
        $this->line("→ {$label}");
        $fn();
        $this->line('  done (' . number_format(microtime(true) - $start, 1) . 's)');
    }
}
