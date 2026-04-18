<?php

namespace App\Console\Commands;

use App\Services\BulkSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Full Scryfall reference-data sync. Intended cadence: weekly.
 *
 * Runs four phases in order:
 *   1. syncSets()         — refresh set catalogue
 *   2. syncBulkCards()    — Default Cards JSON → scryfall_cards
 *   3. syncOracleTags()   — per-tag search → card_oracle_tags
 *   4. handleMigrations() — apply pending Scryfall card-id migrations
 *
 * The bulk JSON file is ~700 MB; full json_decode peaks around 2-3 GB.
 * memory_limit is bumped to 4 G at the top of handle().
 */
class ScryfallSyncBulk extends Command
{
    protected $signature = 'scryfall:sync-bulk';

    protected $description = 'Full Scryfall reference sync: sets, bulk cards, oracle tags, migrations.';

    public function handle(BulkSyncService $bulk): int
    {
        // The full file decode peaks ~3 GB on PHP 8.4 with the current Default
        // Cards file. 4 GB gives comfortable headroom.
        ini_set('memory_limit', '4G');

        $this->info('Scryfall bulk sync starting…');

        try {
            $this->step('1/4  syncSets', fn () => $bulk->syncSets());

            $this->step('2/4  syncBulkCards', function () use ($bulk) {
                $bar = null;
                $bulk->syncBulkCards(function (int $processed, int $total) use (&$bar) {
                    if ($bar === null) {
                        $bar = $this->output->createProgressBar($total);
                        $bar->start();
                    }
                    $bar->setProgress($processed);
                });
                if ($bar) {
                    $bar->finish();
                    $this->newLine();
                }
            });

            $this->step('3/4  syncOracleTags', fn () => $bulk->syncOracleTags());
            $this->step('4/4  handleMigrations', fn () => $bulk->handleMigrations());
        } catch (Throwable $e) {
            $this->error('Bulk sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Scryfall bulk sync complete.');
        return self::SUCCESS;
    }

    /** Wrap a step with timing + label output. */
    private function step(string $label, callable $fn): void
    {
        $start = microtime(true);
        $this->line("→ {$label}");
        $fn();
        $elapsed = number_format(microtime(true) - $start, 1);
        $this->line("  done ({$elapsed}s)");
    }
}
