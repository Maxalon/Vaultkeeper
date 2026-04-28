<?php

namespace App\Http\Controllers;

use App\Models\HorizonAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Auth subrequest backing the /db Adminer mount.
 *
 * nginx's `auth_request /__db_auth` on the /db location calls this
 * endpoint on every request and forwards the original cookies. A 204
 * lets the request through to the adminer container, 401 is caught by
 * the @db_login named location and the operator is bounced to
 * /horizon-login (which itself redirects to /horizon-setup when no
 * admin exists yet).
 *
 * The actual /db -> adminer:8080 proxy is done by nginx itself now, so
 * the original request method, headers, and body reach Adminer intact.
 * The previous X-Accel-Redirect-from-FastCGI scheme dropped POST bodies
 * on internal subrequests, which silently broke Adminer's login form.
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
