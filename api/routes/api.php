<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1'); // 10 attempts/minute/IP — brute force protection
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout',  [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me',       [AuthController::class, 'me']);
    });

    Route::apiResource('cards', CardController::class);
    Route::apiResource('decks', DeckController::class);

    Route::get('locations',                [LocationController::class, 'index']);
    Route::post('locations',               [LocationController::class, 'store']);
    Route::put('locations/{location}',     [LocationController::class, 'update']);
    Route::delete('locations/{location}',  [LocationController::class, 'destroy']);

    Route::get('collection',               [CollectionController::class, 'index']);
    Route::get('collection/{entry}',       [CollectionController::class, 'show']);
    Route::patch('collection/{entry}',     [CollectionController::class, 'update']);
    Route::delete('collection/{entry}',    [CollectionController::class, 'destroy']);

    Route::post('import', [ImportController::class, 'store']);
});
