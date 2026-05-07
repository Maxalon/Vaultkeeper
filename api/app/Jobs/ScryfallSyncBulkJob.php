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
 * Routed to the dedicated `scryfall` queue on the `redis-long` connection
 * so the 60s default-supervisor timeout doesn't kill it mid-run. Job
 * $timeout (1800s) sits below supervisor-scryfall timeout (1860s) and
 * the redis-long retry_after (1920s) — that ordering is what prevents
 * MaxAttemptsExceededException on long jobs.
 */
class ScryfallSyncBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $connection = 'redis-long';
    public $queue      = 'scryfall';
    public int $timeout = 1800;
    public int $tries   = 1;

    public function handle(): void
    {
        Artisan::call('scryfall:sync-bulk');
    }
}
