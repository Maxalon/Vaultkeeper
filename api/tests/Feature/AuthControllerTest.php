<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
