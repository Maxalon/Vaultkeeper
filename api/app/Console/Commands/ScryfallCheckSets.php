<?php

namespace App\Console\Commands;

use App\Models\MtgSet;
use App\Services\BulkSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily set-count check. Refreshes the `sets` table from Scryfall and runs
 * a targeted syncSet() for any set whose card_count diverges from our
 * locally-stored count — usually indicating a new printing or a card
 * reveal landed mid-week between full bulk syncs.
 */
class ScryfallCheckSets extends Command
{
    protected $signature = 'scryfall:check-sets';

    protected $description = 'Daily check: refresh sets, resync any with a card-count mismatch.';

    public function handle(BulkSyncService $bulk): int
    {
        $this->info('Scryfall set-check starting…');

        try {
            $bulk->syncSets();
        } catch (Throwable $e) {
            $this->error('syncSets failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $mismatched = MtgSet::query()
            ->whereColumn('card_count', '!=', 'our_card_count')
            ->orderBy('code')
            ->get(['code', 'name', 'card_count', 'our_card_count']);

        if ($mismatched->isEmpty()) {
            $this->info('No mismatches — every set matches Scryfall.');
            return self::SUCCESS;
        }

        $this->info("{$mismatched->count()} set(s) need targeted resync:");
        foreach ($mismatched as $s) {
            $delta = $s->card_count - $s->our_card_count;
            $sign  = $delta > 0 ? '+' : '';
            $this->line("  {$s->code}  {$s->name}  ({$sign}{$delta})");
        }

        foreach ($mismatched as $s) {
            try {
                $result = $bulk->syncSet($s->code);
                $this->line("  ✓ {$s->code} synced ({$result['cards']} cards)");
            } catch (Throwable $e) {
                $this->error("  ✗ {$s->code} failed: " . $e->getMessage());
            }
        }

        $this->info('Scryfall set-check complete.');
        return self::SUCCESS;
    }
}
