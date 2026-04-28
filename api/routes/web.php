<?php

use App\Http\Controllers\HorizonAuthController;
use App\Http\Controllers\OpsDbProxyController;
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

// ─── Adminer DB UI auth subrequest ───────────────────────────────────────
// nginx mounts Adminer at /db and gates every request via
// `auth_request /__db_auth` against this endpoint. 204 lets the request
// through to the adminer container; 401 bounces the operator to
// /horizon-login via nginx's @db_login named location. The proxy to
// adminer:8080 is done by nginx itself now — Laravel never touches the
// real /db traffic.
Route::get('/__db_auth', [OpsDbProxyController::class, 'check']);
