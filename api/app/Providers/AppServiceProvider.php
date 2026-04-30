<?php

namespace App\Providers;

use App\Models\Deck;
use App\Observers\DeckObserver;
use Illuminate\Auth\Notifications\ResetPassword;
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
