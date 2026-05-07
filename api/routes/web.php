<?php

use App\Http\Controllers\HorizonAuthController;
use App\Http\Controllers\OpsDbProxyController;
use Illuminate\Support\Facades\Route;

// ─── Horizon dashboard auth ──────────────────────────────────────────────
// Horizon is mounted on its own subdomain (HORIZON_DOMAIN). The path
// MUST NOT be `/` — the Laravel\Horizon package's SPA catch-all
// (`Route::get('/{view?}')->where('view', '(.*)')`) is registered during
// the package provider's boot() and shadows every other URL on the
// configured (domain, prefix). PR #180 tried to outrun this by
// declaring these auth routes from AppServiceProvider::register(); in
// Laravel 11 that turns out to lose the match anyway (the package
// provider's catch-all wins on `/login` for reasons that don't match
// the documented register/boot ordering — verified empirically with
// `app('router')->getRoutes()->match()`).
//
// So: keep HORIZON_PATH at a non-root prefix (we use `/dashboard`).
// That confines Horizon's catch-all to `/dashboard/...` and leaves
// /, /login, /setup, /logout free for these routes to claim.
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
