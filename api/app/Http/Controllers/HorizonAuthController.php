<?php

namespace App\Http\Controllers;

use App\Models\HorizonAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Handles the password-protected entry into the Horizon dashboard.
 *
 * First-access setup
 * ──────────────────
 * When no admin row exists, GET /horizon-setup renders a "choose a password"
 * form gated by a one-time setup token. The token is generated lazily on
 * the first GET, cached in Redis for 24h, and emitted to the application
 * log so the operator retrieves it from `docker compose logs api`. After
 * a successful POST the token is invalidated and the form 404s on every
 * future visit.
 *
 * Login
 * ─────
 * Once an admin exists, GET /horizon-login renders a single-field password
 * form. POST verifies the bcrypt hash and writes `horizon_authed=true` to
 * the session. The Horizon gate (HorizonServiceProvider) reads that key.
 *
 * Logout
 * ──────
 * POST /horizon-logout drops the session flag and bounces back to login.
 */
class HorizonAuthController extends Controller
{
    private const SETUP_TOKEN_KEY = 'horizon-setup-token';
    private const SESSION_AUTHED  = 'horizon_authed';
    private const PASSWORD_RULES  = ['required', 'string', 'min:12', 'max:200', 'confirmed'];

    public function showSetup(): View|RedirectResponse
    {
        if (HorizonAdmin::query()->exists()) {
            // Already configured — push the operator to the login form
            // instead of rendering 404 (less confusing).
            return redirect('/horizon-login');
        }

        $token = Cache::get(self::SETUP_TOKEN_KEY);
        if (! $token) {
            $token = Str::random(48);
            // 24h is enough for the operator to fetch from logs and set
            // the password, but short enough that an unused token won't
            // sit around forever.
            Cache::put(self::SETUP_TOKEN_KEY, $token, now()->addHours(24));
            // warning level so the line stands out at the default
            // LOG_LEVEL=warning prod runs on. The token is the SECRET —
            // anyone with log access can complete first-time setup.
            Log::warning('HORIZON_SETUP_TOKEN issued', ['token' => $token]);
        }

        return view('horizon.setup');
    }

    public function setup(Request $request): RedirectResponse
    {
        if (HorizonAdmin::query()->exists()) {
            return redirect('/horizon-login');
        }

        $data = $request->validate([
            'token'    => 'required|string',
            'password' => self::PASSWORD_RULES,
            'next'     => 'nullable|string|max:500',
        ]);

        $expected = Cache::get(self::SETUP_TOKEN_KEY);
        if (! $expected || ! hash_equals($expected, (string) $data['token'])) {
            return back()
                ->withErrors(['token' => 'Setup token is invalid or expired.'])
                ->onlyInput();
        }

        $admin = HorizonAdmin::create(['password_hash' => Hash::make((string) $data['password'])]);
        Cache::forget(self::SETUP_TOKEN_KEY);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_AUTHED, $this->authToken($admin->password_hash));

        return redirect($this->safeNext($data['next'] ?? null));
    }

    public function showLogin(): View|RedirectResponse
    {
        if (! HorizonAdmin::query()->exists()) {
            return redirect('/horizon-setup');
        }
        return view('horizon.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (! HorizonAdmin::query()->exists()) {
            return redirect('/horizon-setup');
        }

        $data = $request->validate([
            'password' => 'required|string|max:200',
            'next'     => 'nullable|string|max:500',
        ]);

        $admin = HorizonAdmin::query()->first();
        if (! $admin || ! Hash::check((string) $data['password'], $admin->password_hash)) {
            return back()
                ->withErrors(['password' => 'Wrong password.']);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_AUTHED, $this->authToken($admin->password_hash));

        return redirect($this->safeNext($data['next'] ?? null));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_AUTHED);
        $request->session()->regenerate();
        return redirect('/horizon-login');
    }

    /**
     * Derive a session-stored token from the admin's bcrypt hash.
     *
     * The session stores this value, and the gate (and middleware) compare
     * what's in the session to what's currently stored on the admin row.
     * Any change to the password — including a `horizon:reset-credentials`
     * wipe — instantly invalidates every active session.
     *
     * SHA-256 over the bcrypt hash itself: the hash is already opaque, so
     * the SHA is just a fixed-length token. We don't expose the bcrypt
     * hash directly to the session store as a tiny defence in depth.
     */
    public static function authToken(string $passwordHash): string
    {
        return hash('sha256', 'horizon|'.$passwordHash);
    }

    /**
     * Whitelist a `?next=` value before using it as a redirect target.
     *
     * Only same-origin paths are allowed: must start with a single `/`
     * and must not start with `//` (which browsers treat as a
     * protocol-relative URL pointing at another host). Anything else
     * falls back to /horizon, the default destination.
     */
    private function safeNext(?string $next): string
    {
        if (is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
            return $next;
        }
        return '/horizon';
    }
}
