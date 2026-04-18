<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Oracle tag categories
    |--------------------------------------------------------------------------
    |
    | Functional categories fetched from Scryfall via `otag:{tag}` queries
    | by BulkSyncService::syncOracleTags(). Add or remove categories here;
    | the next sync will pick up the change. Tags are case-sensitive and
    | must match Scryfall's tagger vocabulary exactly.
    |
    */
    'oracle_tags' => [
        'ramp', 'draw', 'removal', 'boardwipe', 'counterspell', 'tutor',
        'reanimation', 'protection', 'recursion', 'haste-granter', 'pump',
        'token-maker', 'sacrifice-outlet', 'graveyard-hate', 'land-destruction',
        'extra-turn', 'copy-spell', 'blink', 'bounce', 'burn', 'lifegain',
        'mana-rock', 'mana-dork', 'equipment', 'aura', 'combo-piece',
        'stax', 'tax', 'wheel', 'mill',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk download directory
    |--------------------------------------------------------------------------
    |
    | Where the Default Cards JSON file is staged before processing.
    | Files are deleted after a successful sync.
    |
    */
    // Must match the 'local' disk root (Laravel 11 defaults to app/private).
    // BulkSyncService writes to this dir via Storage::disk('local') and then
    // reads the file back from this absolute path.
    'bulk_dir' => storage_path('app/private/scryfall-bulk'),
];
