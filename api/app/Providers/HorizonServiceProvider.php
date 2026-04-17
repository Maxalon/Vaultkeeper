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
     * Access in non-local environments is restricted to the email addresses
     * listed in HORIZON_ALLOWED_EMAILS (comma-separated) in the environment.
     * In the local environment the parent class grants access to everyone so
     * we don't override that.
     *
     * The allowlist lives in env instead of a database column so we don't
     * need an admin-user schema change just to lock down /horizon, and so
     * prod can rotate allowed operators by editing .env and redeploying.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $allowed = array_filter(array_map(
                'trim',
                explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))
            ));

            if (empty($allowed)) {
                return false;
            }

            return in_array(optional($user)->email, $allowed, true);
        });
    }
}
