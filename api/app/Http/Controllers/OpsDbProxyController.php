<?php

namespace App\Http\Controllers;

use App\Models\HorizonAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Front door for the Adminer DB UI exposed at /db.
 *
 * Every request to /db/* funnels through here so the same ops-password
 * session that protects /horizon also gates database access. After the
 * session check passes, we hand off to nginx via X-Accel-Redirect to an
 * internal-only location that proxies to the adminer container — Laravel
 * never copies a single byte of Adminer's response.
 *
 * Two layers of auth survive past this controller:
 *   1) the session check below (same horizon_authed token as RequireHorizonAuth)
 *   2) Adminer's own MySQL login form, which takes the DB credentials from
 *      .env.<env> at first use and remembers them in its own cookie.
 */
class OpsDbProxyController extends Controller
{
    public function proxy(Request $request, ?string $path = null): Response|RedirectResponse
    {
        $admin = HorizonAdmin::query()->first();
        if (! $admin) {
            return redirect('/horizon-setup');
        }

        $sessionToken = $request->session()->get('horizon_authed');
        $expected = HorizonAuthController::authToken($admin->password_hash);
        if (! is_string($sessionToken) || $sessionToken === '' || ! hash_equals($expected, $sessionToken)) {
            return redirect('/horizon-login?next=' . urlencode($request->getRequestUri()));
        }

        $rewritten = '/__db_internal/' . ltrim((string) $path, '/');
        if ($qs = $request->getQueryString()) {
            $rewritten .= '?' . $qs;
        }

        return response('', 200)->withHeaders([
            'X-Accel-Redirect' => $rewritten,
            'Content-Type'     => '',
        ]);
    }
}
