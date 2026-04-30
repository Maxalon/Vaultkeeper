<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Queue wrapper around `scryfall:sync-bulk` so the weekly full-sync shows
 * up in Horizon. The command itself stays usable from the CLI for ad-hoc
 * debugging.
 *
 * Timeout is generous (4h) — full bulk + oracle-tags + oracle-table can
 * push past an hour on production-sized data, and Scryfall rate-limits
 * the per-tag fetches hard.
 */
class ScryfallSyncBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;
    public int $tries   = 1;

    public function handle(): void
    {
        Artisan::call('scryfall:sync-bulk');
    }
}
