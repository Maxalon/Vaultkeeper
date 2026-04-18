<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Set-symbol assets (SVGs on disk) — unrelated to the Scryfall reference DB.
Schedule::command('sets:sync-new')->daily();

// Full Scryfall reference sync (sets + scryfall_cards + oracle tags + migrations).
// Heavy: downloads ~700MB and processes ~100k cards. Sunday 03:00 keeps it
// out of weekday peak hours and away from the 03:15 backup window.
Schedule::command('scryfall:sync-bulk')->weeklyOn(0, '03:00');

// Light daily check that catches new printings between full bulk syncs.
Schedule::command('scryfall:check-sets')->dailyAt('04:00');
