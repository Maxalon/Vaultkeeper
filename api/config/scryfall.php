<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Oracle tag categories
    |--------------------------------------------------------------------------
    |
    | Functional categories fetched from Scryfall via `otag:{tag}` queries
    | by BulkSyncService::syncOracleTags(). Tags are case-sensitive and must
    | match Scryfall's tagger vocabulary exactly.
    |
    | ORDER MATTERS — DeckEntryController::autoCategory() returns the FIRST
    | matching tag from this list, so this list IS the priority for cards
    | with multiple tags. Examples that drive the order below:
    |
    |   Rhystic Study      = draw + tax                → draw
    |   Sol Ring           = ramp + mana-rock          → mana-rock
    |   Llanowar Elves     = ramp + mana-dork          → mana-dork
    |   Wrath of God       = removal + boardwipe       → boardwipe
    |   Wheel of Fortune   = draw + wheel              → wheel
    |   Animate Dead       = recursion + reanimate     → reanimate
    |   Lightning Bolt     = removal + burn            → burn
    |   Cyclonic Rift      = removal + bounce          → bounce
    |   Survival of Fittest= sacrifice-outlet + tutor  → tutor
    |
    | General principle: more specific / more defining function wins.
    |
    */
    'oracle_tags' => [
        // Tier 1: singular, game-defining function — beats everything else
        // a card might also do.
        'tutor',

        // Tier 2: removal subtypes, specific → general. Boardwipes are
        // the most notable removal mode; counterspells are their own
        // identity, not really "removal" but functionally adjacent.
        'boardwipe', 'counterspell',

        // Tier 3: card draw, specific → general.
        'wheel', 'draw',

        // Tier 4: recursion, specific → general.
        'reanimate', 'recursion',

        // Tier 5: mana production, specific → general. deckStats.js's
        // producerCardsByColor() reads all three names directly, so do
        // not rename without updating the frontend.
        'mana-rock', 'mana-dork', 'ramp',

        // Tier 6: single-target / partial removal, specific → general.
        'bounce', 'burn', 'removal',

        // Tier 7: niche utility — last resort when nothing higher matched.
        'blink', 'sacrifice-outlet', 'graveyard-hate',
        'mill', 'tax', 'lifegain',
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
