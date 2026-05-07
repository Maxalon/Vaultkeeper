<?php

namespace App\Providers;

use App\Http\Controllers\HorizonAuthController;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Observers\DeckEntryObserver;
use App\Observers\DeckObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Horizon dashboard auth pages — see HorizonAuthController for the
        // setup/login/logout flow.
        //
        // These MUST register before Laravel\Horizon's package provider
        // boots. With HORIZON_PATH=/ on horizon.vault[-staging].* the
        // package's SPA catch-all (`Route::get('/{view?}')->where(...)`)
        // claims every URL on the subdomain. Route matching is
        // first-registered-wins, and routes/web.php — which Laravel 11's
        // withRouting() loads from `$app->booted()` — comes AFTER all
        // provider boot()s, so anything declared there loses the match.
        // Doing it here in register() puts these into the route collection
        // before any boot()-time route registration.
        //
        // Throttle: 5 attempts/min/IP on POSTs to slow down brute-forcing
        // the login form and dampen scrapes of the setup endpoint between
        // deploy and first browser visit.
        Route::middleware('web')->group(function () {
            Route::get ('/setup',  [HorizonAuthController::class, 'showSetup']);
            Route::post('/setup',  [HorizonAuthController::class, 'setup'])
                ->middleware('throttle:5,1');
            Route::get ('/login',  [HorizonAuthController::class, 'showLogin']);
            Route::post('/login',  [HorizonAuthController::class, 'login'])
                ->middleware('throttle:5,1');
            Route::post('/logout', [HorizonAuthController::class, 'logout']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Deck::observe(DeckObserver::class);
        DeckEntry::observe(DeckEntryObserver::class);

        // The default ResetPassword notification builds a URL pointing at a
        // Laravel route. We serve the SPA on the same origin as the API, so
        // rewrite the URL to land on the SPA's reset page instead.
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            return rtrim(config('app.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
