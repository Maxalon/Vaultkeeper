<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * The dashboard is gated by a per-environment password chosen at first
     * access via /horizon-setup (HorizonAuthController). The session value
     * `horizon_authed` holds a token derived from the admin's password
     * hash; we compare it to the current hash so any password change or
     * `horizon:reset-credentials` wipe instantly invalidates every active
     * session. RequireHorizonAuth (registered in config/horizon.php) does
     * the same check at the middleware layer to redirect unauthenticated
     * visitors to the setup/login form; this gate is the second line of
     * defence if that middleware is misconfigured.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $admin = \App\Models\HorizonAdmin::query()->first();
            if (! $admin) return false;

            $sessionToken = session('horizon_authed');
            if (! is_string($sessionToken) || $sessionToken === '') return false;

            return hash_equals(
                \App\Http\Controllers\HorizonAuthController::authToken($admin->password_hash),
                $sessionToken,
            );
        });
    }
}
