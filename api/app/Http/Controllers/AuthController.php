<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $token = Auth::guard('api')->attempt($credentials);

        if (! $token) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Create a new user and immediately log them in. Email and username must
     * be unique. Password is confirmed against `password_confirmation` on the
     * client side too, but we re-validate here to defend against malformed
     * requests that bypass the form.
     *
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │ KNOWN ACCEPTED RISK — open registration, no email verification      │
     * ├─────────────────────────────────────────────────────────────────────┤
     * │ Anyone who can reach this endpoint can mint a verified-looking      │
     * │ account with a throwaway address and immediately get a JWT. There   │
     * │ is no email-confirmation step. This is an accepted risk because     │
     * │ the deployment lives behind a Tailscale ACL that only admits        │
     * │ pre-approved devices — every caller is already authenticated at    │
     * │ the network layer. We're keeping the convenience of one-click      │
     * │ signup for invited users.                                           │
     * │                                                                     │
     * │ If this service is EVER exposed to the public internet (or to a    │
     * │ wider tailnet, or to a Cloudflare Tunnel), revisit this:           │
     * │   1. Add email verification before granting an API token.          │
     * │   2. Gate decks/import* and import behind `verified` middleware.   │
     * │   3. Add hCaptcha / Turnstile to the registration form.            │
     * │   4. Consider an invite-token requirement.                         │
     * │ Tracked in the security audit as H-6.                               │
     * └─────────────────────────────────────────────────────────────────────┘
     *
     * Account-enumeration hardening (H-2)
     * ───────────────────────────────────
     * Username and email uniqueness are checked manually and surfaced under
     * a single generic `username` error so an attacker can't tell whether
     * they hit a registered username, a registered email, or both. The
     * unique() rule used to leak the difference via field-specific messages.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:50',
                'regex:/^[a-zA-Z0-9_.-]+$/',
            ],
            'email' => [
                'required',
                'string',
                // Laravel's `email:rfc,dns` runs through Egulias\EmailValidator:
                //   rfc — full RFC 5322 syntax (stricter than the default email rule)
                //   dns — domain must resolve an MX record (catches @gmial.com,
                //         @nope.invalid, etc. without sending anything)
                // DNS check is skipped in `local` so dev .local emails (test
                // users) still register; staging + prod get the full deal.
                app()->environment('local')
                    ? 'email:rfc'
                    : 'email:rfc,dns',
                'max:255',
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $taken = User::query()
            ->where('username', $data['username'])
            ->orWhere('email', $data['email'])
            ->exists();

        if ($taken) {
            // Single generic error — never reveal *which* field collided.
            // The SPA renders this under the username input; users with a
            // genuine duplicate are nudged toward the password-reset flow.
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => ['Those credentials are unavailable. If you already have an account, please sign in or reset your password.'],
            ]);
        }

        $user = User::create([
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => $data['password'], // hashed via the model cast
        ]);

        $token = Auth::guard('api')->login($user);

        return $this->respondWithToken($token);
    }

    /**
     * Send a password reset link to the address if it belongs to a user.
     * Always returns 200 with a generic message — surfacing whether the
     * address exists would let an attacker enumerate accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => "If that address is on file, we've sent a reset link.",
        ]);
    }

    /**
     * Consume a reset token and set a new password. Returns 422 with a
     * single `email`-keyed error for any broker failure (bad token,
     * expired token, unknown user) so the SPA can render it under the
     * email field; the token itself is invisible to the user.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password, // hashed via the model cast
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset.']);
        }

        return response()->json([
            'message' => __($status),
            'errors'  => ['email' => [__($status)]],
        ], 422);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Logged out']);
    }

    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(Auth::guard('api')->refresh());
    }

    public function me(): JsonResponse
    {
        return response()->json(Auth::guard('api')->user());
    }

    /**
     * Stamps onboarding_completed_at on the current user. Idempotent — calling
     * it after the user has already finished onboarding is a no-op (we don't
     * overwrite the original timestamp, since "when did this user first land"
     * is more useful than "when did they last click the button").
     */
    public function completeOnboarding(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if ($user->onboarding_completed_at === null) {
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        }

        return response()->json($user);
    }

    protected function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
            'user' => Auth::guard('api')->user(),
        ]);
    }
}
