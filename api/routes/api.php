<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\DeckEntryController;
use App\Http\Controllers\DeckImportController;
use App\Http\Controllers\DeckLegalityController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocationGroupController;
use App\Http\Controllers\ScryfallCardController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1'); // 10 attempts/minute/IP — brute force protection

    // Public — same throttle as login since it's the obvious next abuse vector.
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');
});

// Public — fed to the unauthenticated login hero. Returns one random
// non-land card from the most recently expanded set, or null when the
// catalogue is empty.
Route::get('cards/featured', [CardController::class, 'featured']);

Route::middleware('auth:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout',  [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me',       [AuthController::class, 'me']);
    });

    // Read-only Scryfall reference DB. Defined before apiResource('cards', ...)
    // so the literal `scryfall-cards/search` segment never collides with a
    // parameter binding (the prefixes are different anyway, but explicit > implicit).
    Route::get('scryfall-cards/search', [ScryfallCardController::class, 'search']);
    Route::get('scryfall-cards/printings', [ScryfallCardController::class, 'printings']);
    Route::get('scryfall-cards/{scryfallCard}', [ScryfallCardController::class, 'show']);

    Route::apiResource('cards', CardController::class);

    Route::get   ('decks',                                 [DeckController::class, 'index']);
    Route::post  ('decks',                                 [DeckController::class, 'store']);
    Route::post  ('decks/import',                          [DeckImportController::class, 'store']);
    Route::post  ('decks/import/bulk',                     [DeckImportController::class, 'bulk']);
    Route::get   ('decks/import/bulk/{key}',               [DeckImportController::class, 'bulkStatus']);
    Route::get   ('decks/{deck}',                          [DeckController::class, 'show']);
    Route::put   ('decks/{deck}',                          [DeckController::class, 'update']);
    Route::delete('decks/{deck}',                          [DeckController::class, 'destroy']);

    Route::get   ('decks/{deck}/entries',                  [DeckEntryController::class, 'index']);
    Route::post  ('decks/{deck}/entries',                  [DeckEntryController::class, 'store']);
    Route::patch ('decks/{deck}/entries/{entry}',          [DeckEntryController::class, 'update']);
    Route::delete('decks/{deck}/entries/{entry}',          [DeckEntryController::class, 'destroy']);

    Route::get ('decks/{deck}/illegalities',               [DeckLegalityController::class, 'index']);
    Route::post('decks/{deck}/illegalities/ignore',        [DeckLegalityController::class, 'ignore']);
    Route::post('decks/{deck}/illegalities/unignore',      [DeckLegalityController::class, 'unignore']);

    Route::get('locations',                [LocationController::class, 'index']);
    Route::post('locations',               [LocationController::class, 'store']);
    Route::put('locations/{location}',     [LocationController::class, 'update']);
    Route::delete('locations/{location}',  [LocationController::class, 'destroy']);

    Route::get('location-groups',               [LocationGroupController::class, 'index']);
    Route::post('location-groups',              [LocationGroupController::class, 'store']);
    Route::post('location-groups/reorder',      [LocationGroupController::class, 'reorder']);
    Route::put('location-groups/{group}',       [LocationGroupController::class, 'update']);
    Route::delete('location-groups/{group}',    [LocationGroupController::class, 'destroy']);

    Route::get('collection',               [CollectionController::class, 'index']);
    Route::get('collection/copies',        [CollectionController::class, 'copiesForCard']);
    Route::post('collection/batch-move',   [CollectionController::class, 'batchMove']);
    Route::get('collection/{entry}',       [CollectionController::class, 'show']);
    Route::patch('collection/{entry}',     [CollectionController::class, 'update']);
    Route::delete('collection/{entry}',    [CollectionController::class, 'destroy']);

    Route::post('import', [ImportController::class, 'store']);
});
