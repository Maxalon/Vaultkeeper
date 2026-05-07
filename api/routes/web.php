<?php

use App\Http\Controllers\HorizonAuthController;
use App\Http\Controllers\OpsDbProxyController;
use Illuminate\Support\Facades\Route;

// ─── Horizon dashboard auth ──────────────────────────────────────────────
// Horizon is mounted on its own subdomain (HORIZON_DOMAIN) at a
// non-root prefix (HORIZON_PATH=`dashboard`). The constraints on
// HORIZON_PATH are documented at length in .env.prod.example; the
// summary is:
//
//   1. Empty / `/` lets the package's SPA catch-all
//      (Route::get('/{view?}')) shadow every URL on the subdomain,
//      including /login and /setup — redirect loop.
//   2. A LEADING-SLASH value (e.g. `/dashboard`) breaks Horizon's
//      frontend, which builds API URLs as `'/' + config.path + ...`.
//      With path=`/dashboard` that interpolates to `//dashboard/...`
//      — a protocol-relative URL pointing at host `dashboard`.
//
// So HORIZON_PATH must be a non-empty, non-leading-slash prefix; the
// auth routes below claim /, /login, /setup, /logout under that prefix
// boundary on the same subdomain.
//
// Throttle: 5 attempts/min/IP on POSTs to slow down brute-forcing the
// login form and dampen scrapes of the setup endpoint between deploy and
// first browser visit.
Route::get ('/setup',  [HorizonAuthController::class, 'showSetup']);
Route::post('/setup',  [HorizonAuthController::class, 'setup'])
    ->middleware('throttle:5,1');
Route::get ('/login',  [HorizonAuthController::class, 'showLogin']);
Route::post('/login',  [HorizonAuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/logout', [HorizonAuthController::class, 'logout']);

// ─── Adminer DB UI auth subrequest ───────────────────────────────────────
// Caddy's `forward_auth` on adminer.vault.* calls this endpoint via the
// internal :9100 HTTP shim before forwarding the request to the adminer
// container. 204 lets the request through; 401 is intercepted by Caddy
// and the operator is bounced to /login on horizon.vault.*.
Route::get('/__db_auth', [OpsDbProxyController::class, 'check']);
