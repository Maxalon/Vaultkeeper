<?php

use App\Http\Controllers\OpsDbProxyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ─── Adminer DB UI auth subrequest ───────────────────────────────────────
// Caddy gates adminer.vault[-staging].* with `forward_auth` against this
// endpoint. 204 lets the request through to the adminer container; 401
// bounces the operator to /login on horizon.vault[-staging].* via Caddy's
// `handle_response @denied` block. The proxy to adminer:8080 is done by
// Caddy itself — Laravel never touches the real /db traffic.
Route::get('/__db_auth', [OpsDbProxyController::class, 'check']);

// The Horizon-dashboard auth pages (/setup, /login, /logout) are
// registered in AppServiceProvider::register() instead of here, on
// purpose. With HORIZON_PATH=/, the Laravel\Horizon package's SPA
// catch-all claims every URL on horizon.vault[-staging].* and is
// registered during provider boot — earlier than this file, which
// withRouting() loads from `$app->booted()`. Declaring them at
// register-time is what puts them ahead of the catch-all in the
// route collection.
