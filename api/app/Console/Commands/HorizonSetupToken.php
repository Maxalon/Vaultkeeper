<?php

namespace App\Console\Commands;

use App\Models\HorizonAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Backup retrieval channel for the /setup token.
 *
 * Email is the primary delivery mechanism (HORIZON_SETUP_EMAIL), but if
 * the operator never received it (mail outage, env var missing, lost the
 * message) this command surfaces the currently-cached token, or mints a
 * new one if none is pending.
 *
 * Refuses to run once an admin row exists — at that point /setup is
 * closed and there is no setup token to print.
 */
class HorizonSetupToken extends Command
{
    private const SETUP_TOKEN_KEY = 'horizon-setup-token';

    protected $signature = 'horizon:setup-token';

    protected $description = 'Print the pending /setup token (mints one if none is cached)';

    public function handle(): int
    {
        if (HorizonAdmin::query()->exists()) {
            $this->error('Horizon is already configured. Use `horizon:reset-credentials` if you need to start over.');
            return self::FAILURE;
        }

        $token = Cache::get(self::SETUP_TOKEN_KEY);
        if (! $token) {
            $token = Str::random(48);
            Cache::put(self::SETUP_TOKEN_KEY, $token, now()->addHours(24));
            $this->info('No token was cached — issued a fresh one.');
        }

        $this->newLine();
        $this->line('Setup token:');
        $this->line('  '.$token);
        $this->newLine();
        $this->line('Visit /setup and paste the token to choose a password.');
        $this->line('Token expires 24h after it was first issued.');

        return self::SUCCESS;
    }
}
