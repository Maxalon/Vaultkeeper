<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Queue wrapper around `scryfall:sync-prices` so the daily price refresh
 * shows up in Horizon. The artisan command itself stays usable from the
 * CLI for ad-hoc debugging.
 *
 * Timeout is generous (1h) — the bulk file is the same ~700 MB the
 * weekly bulk sync downloads, but with only a single linear pass to
 * extract prices the job typically completes in under 10 minutes.
 */
class ScryfallSyncPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function handle(): void
    {
        Artisan::call('scryfall:sync-prices');
    }
}
