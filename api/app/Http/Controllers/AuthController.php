<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
     * Create a new user and immediately log them in. Email and username are
     * unique. Password is confirmed against `password_confirmation` on the
     * client side too, but we re-validate here to defend against malformed
     * requests that bypass the form.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:50',
                'regex:/^[a-zA-Z0-9_.-]+$/',
                Rule::unique('users', 'username'),
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
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => $data['password'], // hashed via the model cast
        ]);

        $token = Auth::guard('api')->login($user);

        return $this->respondWithToken($token);
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
