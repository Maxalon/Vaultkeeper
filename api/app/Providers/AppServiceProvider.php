<?php

namespace App\Providers;

use App\Models\Deck;
use App\Observers\DeckObserver;
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
    }
}
