<?php

namespace App\Http\Middleware;

use App\Http\Controllers\HorizonAuthController;
use App\Models\HorizonAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every Horizon-package route on the session token set by
 * HorizonAuthController. If the user isn't authed (no token, expired
 * token, or password rotated since login) we bounce them to the setup
 * or login form depending on whether an admin row exists — an
 * unauthenticated visit never sees a bare 403.
 *
 * Defence-in-depth note: HorizonServiceProvider::gate() runs the same
 * check, so a misconfigured deploy that drops this middleware still
 * falls back to a hard deny instead of leaking the dashboard.
 */
class RequireHorizonAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = HorizonAdmin::query()->first();
        if (! $admin) {
            return redirect('/horizon-setup');
        }

        $sessionToken = $request->session()->get('horizon_authed');
        if (is_string($sessionToken) && $sessionToken !== '' && hash_equals(
            HorizonAuthController::authToken($admin->password_hash),
            $sessionToken,
        )) {
            return $next($request);
        }

        return redirect('/horizon-login');
    }
}
