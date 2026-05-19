<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    // -------------------------------------------------------------------------
    // POST /auth/device-token
    // -------------------------------------------------------------------------

    public function test_register_creates_device_token(): void
    {
        [$user, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [
                'token' => 'abc123',
                'platform' => 'fcm',
            ])
            ->assertStatus(201)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('platform', 'fcm')
            ->assertJsonPath('token', 'abc123');

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'platform' => 'fcm',
            'token' => 'abc123',
        ]);
    }

    public function test_register_updates_token_when_platform_already_registered(): void
    {
        [$user, $headers] = $this->actingAsUser();

        DeviceToken::create([
            'user_id' => $user->id,
            'platform' => 'fcm',
            'token' => 'old-token',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [
                'token' => 'new-token',
                'platform' => 'fcm',
            ])
            ->assertOk()
            ->assertJsonPath('token', 'new-token');

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'platform' => 'fcm',
            'token' => 'new-token',
        ]);
    }

    public function test_register_allows_separate_tokens_per_platform(): void
    {
        [$user, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [
                'token' => 'fcm-token',
                'platform' => 'fcm',
            ])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [
                'token' => 'apns-token',
                'platform' => 'apns',
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('device_tokens', 2);
    }

    public function test_register_validates_required_fields(): void
    {
        [, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'platform']);
    }

    public function test_register_rejects_invalid_platform(): void
    {
        [, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->postJson('/api/auth/device-token', [
                'token' => 'xyz',
                'platform' => 'unknown',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_register_rejects_unauthenticated_request(): void
    {
        $this->postJson('/api/auth/device-token', [
            'token' => 'abc',
            'platform' => 'fcm',
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // DELETE /auth/device-token
    // -------------------------------------------------------------------------

    public function test_delete_removes_device_token(): void
    {
        [$user, $headers] = $this->actingAsUser();

        DeviceToken::create([
            'user_id' => $user->id,
            'platform' => 'fcm',
            'token' => 'some-token',
        ]);

        $this->withHeaders($headers)
            ->deleteJson('/api/auth/device-token', ['platform' => 'fcm'])
            ->assertOk()
            ->assertJsonPath('message', 'Device token removed');

        $this->assertDatabaseMissing('device_tokens', [
            'user_id' => $user->id,
            'platform' => 'fcm',
        ]);
    }

    public function test_delete_only_removes_the_specified_platform(): void
    {
        [$user, $headers] = $this->actingAsUser();

        DeviceToken::create(['user_id' => $user->id, 'platform' => 'fcm',  'token' => 'fcm-token']);
        DeviceToken::create(['user_id' => $user->id, 'platform' => 'apns', 'token' => 'apns-token']);

        $this->withHeaders($headers)
            ->deleteJson('/api/auth/device-token', ['platform' => 'fcm'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['user_id' => $user->id, 'platform' => 'fcm']);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'platform' => 'apns']);
    }

    public function test_delete_is_idempotent_when_token_does_not_exist(): void
    {
        [, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->deleteJson('/api/auth/device-token', ['platform' => 'fcm'])
            ->assertOk();
    }

    public function test_delete_does_not_affect_another_users_token(): void
    {
        [$user, $headers] = $this->actingAsUser();
        $other = User::factory()->create();

        DeviceToken::create(['user_id' => $other->id, 'platform' => 'fcm', 'token' => 'other-token']);

        $this->withHeaders($headers)
            ->deleteJson('/api/auth/device-token', ['platform' => 'fcm'])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', ['user_id' => $other->id, 'platform' => 'fcm']);
    }

    public function test_delete_validates_required_platform(): void
    {
        [, $headers] = $this->actingAsUser();

        $this->withHeaders($headers)
            ->deleteJson('/api/auth/device-token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_delete_rejects_unauthenticated_request(): void
    {
        $this->deleteJson('/api/auth/device-token', ['platform' => 'fcm'])
            ->assertUnauthorized();
    }
}
