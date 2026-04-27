<?php

namespace App\Console\Commands;

use App\Models\HorizonAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wipes the dashboard password and any pending setup token, returning the
 * current environment to "first-access" state. Use when the password is
 * lost or after staffing changes.
 */
class HorizonResetCredentials extends Command
{
    protected $signature = 'horizon:reset-credentials {--force : Skip confirmation}';

    protected $description = 'Clear the Horizon dashboard password so /horizon-setup is available again';

    public function handle(): int
    {
        $hasAdmin = HorizonAdmin::query()->exists();
        $hasToken = Cache::has('horizon-setup-token');

        if (! $hasAdmin && ! $hasToken) {
            $this->info('Nothing to reset — no admin row or pending setup token.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will delete the Horizon password and force re-setup. Continue?', false,
        )) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            HorizonAdmin::query()->delete();
        });
        Cache::forget('horizon-setup-token');

        // Active sessions are invalidated automatically: the gate +
        // RequireHorizonAuth middleware compare the session token to the
        // current admin row's password hash, and there's no admin row to
        // compare against now. A fresh /horizon-setup is the only path back
        // in.
        $this->info('Cleared. Visit /horizon-setup to choose a new password.');
        return self::SUCCESS;
    }
}
