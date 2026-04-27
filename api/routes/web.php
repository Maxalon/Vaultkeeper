<?php

use App\Http\Controllers\HorizonAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ─── Horizon dashboard auth ──────────────────────────────────────────────
// Single-password gate per environment — see HorizonAuthController for the
// flow. Logout is POST so a stolen GET URL can't sign someone out.
//
// Throttle: 5 attempts/min/IP on POSTs to slow down brute-forcing the
// login form and dampen scrapes of the setup endpoint between deploy and
// first browser visit.
Route::get ('/horizon-setup',  [HorizonAuthController::class, 'showSetup']);
Route::post('/horizon-setup',  [HorizonAuthController::class, 'setup'])
    ->middleware('throttle:5,1');
Route::get ('/horizon-login',  [HorizonAuthController::class, 'showLogin']);
Route::post('/horizon-login',  [HorizonAuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/horizon-logout', [HorizonAuthController::class, 'logout']);
