<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_on_valid_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'alice',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'alice',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'username', 'email']])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_rejects_invalid_password(): void
    {
        User::factory()->create([
            'username' => 'alice',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'alice',
            'password' => 'wrong',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_login_rejects_unknown_user(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'nobody',
            'password' => 'whatever',
        ])->assertUnauthorized();
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_is_rate_limited_after_ten_attempts(): void
    {
        // 10 attempts/minute/IP — the 11th must be blocked with 429
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'nobody',
                'password' => 'bad',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'username' => 'nobody',
            'password' => 'bad',
        ])->assertStatus(429);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('username', $user->username);
    }

    public function test_me_rejects_unauthenticated_request(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out');
    }

    public function test_refresh_returns_a_new_token(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_complete_onboarding_stamps_timestamp_for_new_user(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_complete_onboarding_is_idempotent(): void
    {
        $original = now()->subDays(7);
        $user = User::factory()->create(['onboarding_completed_at' => $original]);
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/onboarding/complete')
            ->assertOk();

        // Existing timestamp must be preserved — we want "first welcomed" not
        // "last clicked".
        $this->assertEquals(
            $original->toDateTimeString(),
            $user->fresh()->onboarding_completed_at->toDateTimeString(),
        );
    }

    public function test_complete_onboarding_rejects_unauthenticated_request(): void
    {
        $this->postJson('/api/auth/onboarding/complete')->assertUnauthorized();
    }

    public function test_forgot_password_sends_reset_link_for_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'collector@example.test']);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'collector@example.test',
        ])->assertOk()
            ->assertJsonPath('message', "If that address is on file, we've sent a reset link.");

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_returns_generic_response_for_unknown_email(): void
    {
        Notification::fake();

        // Same 200 + generic message as the happy path — no enumeration leak.
        $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@example.test',
        ])->assertOk()
            ->assertJsonPath('message', "If that address is on file, we've sent a reset link.");

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_sets_new_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email'    => 'collector@example.test',
            'password' => Hash::make('old-password-1234'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'collector@example.test',
            'password'              => 'fresh-password-9999',
            'password_confirmation' => 'fresh-password-9999',
        ])->assertOk()
            ->assertJsonPath('message', 'Password reset.');

        $this->assertTrue(Hash::check('fresh-password-9999', $user->fresh()->password));

        // The freshly minted password should sign the user in cleanly.
        $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'fresh-password-9999',
        ])->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_reset_password_consumes_token_so_it_cannot_be_replayed(): void
    {
        $user = User::factory()->create(['email' => 'collector@example.test']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'collector@example.test',
            'password'              => 'fresh-password-9999',
            'password_confirmation' => 'fresh-password-9999',
        ])->assertOk();

        // Same token, second time — broker must reject it.
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'collector@example.test',
            'password'              => 'another-password-7777',
            'password_confirmation' => 'another-password-7777',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        User::factory()->create(['email' => 'collector@example.test']);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => 'totally-bogus-token',
            'email'                 => 'collector@example.test',
            'password'              => 'fresh-password-9999',
            'password_confirmation' => 'fresh-password-9999',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_rejects_short_password(): void
    {
        $user = User::factory()->create(['email' => 'collector@example.test']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'collector@example.test',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
