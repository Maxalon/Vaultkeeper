<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full Scryfall reference sync (sets + scryfall_cards + oracle tags + migrations).
// Heavy: downloads ~700MB and processes ~100k cards. Sunday 03:00 keeps it
// out of weekday peak hours and away from the 03:15 backup window.
Schedule::command('scryfall:sync-bulk')->weeklyOn(0, '03:00');

// Light daily check that catches new printings between full bulk syncs.
Schedule::command('scryfall:check-sets')->dailyAt('04:00');

// Set-symbol SVG assets. Runs after scryfall:check-sets so the sets table
// is fresh — the backfill step reads icon_svg_uri from there to fill
// rarity slots that Hexproof's catalog doesn't cover (e.g. SLD, promos).
Schedule::command('sets:sync')->dailyAt('04:30');
