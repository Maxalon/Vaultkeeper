<?php

namespace App\Providers;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\User;
use App\Observers\DeckEntryObserver;
use App\Observers\DeckObserver;
use App\Observers\UserObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Deck::observe(DeckObserver::class);
        DeckEntry::observe(DeckEntryObserver::class);
        User::observe(UserObserver::class);

        // The default ResetPassword notification builds a URL pointing at a
        // Laravel route. We serve the SPA on the same origin as the API, so
        // rewrite the URL to land on the SPA's reset page instead.
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            return rtrim(config('app.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // Named limiter for the long-running import endpoints (Archidekt
        // bulk, CSV, text). Inline `throttle:5,1` on a route nested inside
        // a `throttle:120,1` group used to share a cache key with the
        // group throttle (Laravel's default signature for positional
        // throttle args is just `sha1(user_id)`, route-agnostic), so the
        // first SPA page load burned all 5 import attempts on unrelated
        // requests and the first CSV upload 429'd immediately. A named
        // limiter scopes the bucket to imports only.
        RateLimiter::for('imports', function (Request $request) {
            return Limit::perMinute(5)->by('imports:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
