<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Queue wrapper around `scryfall:check-sets` so the daily run shows up in
 * Horizon's completed/failed lists. The command itself stays usable from
 * the CLI for ad-hoc debugging.
 */
class ScryfallCheckSetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    public function handle(): void
    {
        Artisan::call('scryfall:check-sets');
    }
}
