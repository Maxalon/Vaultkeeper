<?php

namespace App\Http\Controllers;

use App\Models\HorizonAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Handles the password-protected entry into the Horizon dashboard.
 *
 * The dashboard is served at the root of horizon.vault.*
 * (HORIZON_PATH=/), and these auth pages live alongside it at /setup,
 * /login, /logout — see routes/web.php for the route definitions.
 *
 * First-access setup
 * ──────────────────
 * When no admin row exists, GET /setup renders a "choose a password"
 * form gated by a one-time setup token. The token is generated lazily on
 * the first GET and cached in Redis for 24h.
 *
 * Token delivery
 * ──────────────
 * The token is delivered by email to HORIZON_SETUP_EMAIL (preferred). The
 * operator should configure that env var before first deploy. As a backup
 * (mail outage, env var not yet set, lost the email), an artisan command
 * is available inside the container:
 *
 *   docker compose exec api php artisan horizon:setup-token
 *
 * which prints the currently-cached token to stdout. The token is NEVER
 * written to the application log — anyone with log access used to be
 * able to complete first-time setup ahead of the legitimate operator.
 *
 * Login
 * ─────
 * Once an admin exists, GET /login renders a single-field password
 * form. POST verifies the bcrypt hash and writes `horizon_authed=true` to
 * the session. The Horizon gate (HorizonServiceProvider) reads that key.
 *
 * Logout
 * ──────
 * POST /logout drops the session flag and bounces back to login.
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
            return redirect('/login');
        }

        $token = Cache::get(self::SETUP_TOKEN_KEY);
        if (! $token) {
            $token = Str::random(48);
            // 24h is enough for the operator to fetch the email and set
            // the password, but short enough that an unused token won't
            // sit around forever.
            Cache::put(self::SETUP_TOKEN_KEY, $token, now()->addHours(24));
            $this->deliverSetupToken($token);
        }

        return view('horizon.setup');
    }

    /**
     * Deliver the freshly-issued setup token to the configured operator.
     *
     * Primary channel: email to HORIZON_SETUP_EMAIL. Fallback: the artisan
     * command `php artisan horizon:setup-token`, which reads the same
     * cache key. We deliberately do NOT write the token to the application
     * log — the previous behavior leaked the secret to anyone with stdout
     * access (CI artifacts, Loki/Grafana, log shippers).
     *
     * If the email send fails (transport down, env not configured), we
     * log an OPERATIONAL warning that does NOT contain the token, so the
     * operator knows to fall back to the artisan command.
     */
    private function deliverSetupToken(string $token): void
    {
        $recipient = (string) config('services.horizon.setup_email', '');

        if ($recipient === '') {
            // No operator email configured — log a hint, NOT the token.
            Log::warning('HORIZON_SETUP_TOKEN issued but HORIZON_SETUP_EMAIL is not set. Run `php artisan horizon:setup-token` inside the api container to retrieve it.');
            return;
        }

        try {
            Mail::raw(
                "A Horizon dashboard setup token has been issued for ".config('app.url').".\n\n"
                ."Token: {$token}\n\n"
                ."Visit /setup on the Horizon subdomain, paste this token, and choose a password.\n"
                ."The token expires in 24 hours.\n\n"
                ."If you did not initiate this, someone reached the /setup\n"
                ."page before an admin was configured. The token alone is harmless\n"
                ."until the password form is submitted, but you should investigate.",
                function ($msg) use ($recipient) {
                    $msg->to($recipient)->subject('Vaultkeeper Horizon — setup token');
                },
            );
            Log::info('HORIZON_SETUP_TOKEN sent by email', ['to' => $recipient]);
        } catch (Throwable $e) {
            // Don't crash the setup page — the operator can always use the
            // artisan-command fallback. Log the failure (no token!).
            Log::error('HORIZON_SETUP_TOKEN email delivery failed; use `php artisan horizon:setup-token` as a fallback.', [
                'to'    => $recipient,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setup(Request $request): RedirectResponse
    {
        if (HorizonAdmin::query()->exists()) {
            return redirect('/login');
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
            return redirect('/setup');
        }
        return view('horizon.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (! HorizonAdmin::query()->exists()) {
            return redirect('/setup');
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
        return redirect('/login');
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
     * falls back to /, the dashboard root.
     */
    private function safeNext(?string $next): string
    {
        if (is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
            return $next;
        }
        return '/';
    }
}
