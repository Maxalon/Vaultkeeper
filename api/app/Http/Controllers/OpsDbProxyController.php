<?php

namespace App\Http\Controllers;

use App\Models\HorizonAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Auth subrequest backing the adminer.vault.* Caddy site.
 *
 * Caddy's `forward_auth` calls this endpoint on every request via the
 * internal :9100 HTTP shim and forwards the original cookies. A 204
 * lets the request through to the adminer container; 401 is caught by
 * Caddy and the operator is bounced to https://horizon.vault.*/login
 * (which itself redirects to /setup when no admin exists yet).
 *
 * The actual adminer.vault.* → adminer:8080 proxy is done by Caddy, so
 * the original request method, headers, and body reach Adminer intact.
 */
class OpsDbProxyController extends Controller
{
    public function check(Request $request): Response
    {
        $admin = HorizonAdmin::query()->first();
        if (! $admin) {
            return response('', 401);
        }

        $sessionToken = $request->session()->get('horizon_authed');
        $expected = HorizonAuthController::authToken($admin->password_hash);
        if (! is_string($sessionToken) || $sessionToken === '' || ! hash_equals($expected, $sessionToken)) {
            return response('', 401);
        }

        return response('', 204);
    }
}
