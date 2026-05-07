<?php

use App\Http\Controllers\HorizonAuthController;
use App\Http\Controllers\OpsDbProxyController;
use Illuminate\Support\Facades\Route;

// ─── Horizon dashboard auth ──────────────────────────────────────────────
// Horizon is mounted at the root of horizon.vault.* (HORIZON_PATH=/), so
// these explicit routes must register before HorizonServiceProvider's
// catch-all `/{view?}` for the auth flow to win. routes/web.php is
// loaded during framework boot, before user providers' boot methods —
// so order is fine as long as the routes stay here.
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
// and the operator is bounced to https://horizon.vault.*/login.
Route::get('/__db_auth', [OpsDbProxyController::class, 'check']);
