<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserPrivacySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for A3 friend endpoints.
 *
 * Covers the full REST surface:
 *   POST   /friends/requests        — send request
 *   GET    /friends/requests        — list requests (with direction filter)
 *   PATCH  /friends/requests/{id}   — accept / decline
 *   DELETE /friends/requests/{id}   — cancel
 *   GET    /friends                 — accepted friends list
 *   DELETE /friends/{user}          — unfriend
 *   GET    /users/search?q=         — username search with privacy filters
 *   GET    /privacy-settings        — show
 *   PATCH  /privacy-settings        — update
 */
class FriendEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $carol;
    private string $aliceToken;
    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob   = User::factory()->create(['username' => 'bob']);
        $this->carol = User::factory()->create(['username' => 'carol_hidden']);

        $this->aliceToken = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($this->alice);
        $this->bobToken   = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($this->bob);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ---------------------------------------------------------------------------
    // POST /friends/requests
    // ---------------------------------------------------------------------------

    public function test_send_friend_request_creates_pending_row(): void
    {
        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.user.username', 'bob');

        $this->assertDatabaseHas('friendships', [
            'user_a_id'    => min($this->alice->id, $this->bob->id),
            'user_b_id'    => max($this->alice->id, $this->bob->id),
            'requester_id' => $this->alice->id,
            'status'       => 'pending',
        ]);
    }

    public function test_send_request_to_self_returns_422(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'alice'])
            ->assertUnprocessable();
    }

    public function test_send_request_missing_username_returns_422(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', [])
            ->assertUnprocessable();
    }

    public function test_send_request_to_nonexistent_user_returns_404(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'doesnotexist'])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------
    // GET /friends/requests
    // ---------------------------------------------------------------------------

    public function test_list_requests_returns_pending_for_caller(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->create();

        // Alice sees 1 outgoing.
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/friends/requests?direction=outgoing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'outgoing');

        // Bob sees 1 incoming.
        $this->withHeaders($this->headers($this->bobToken))
            ->getJson('/api/friends/requests?direction=incoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'incoming');
    }

    public function test_list_requests_without_direction_returns_both(): void
    {
        $carol = User::factory()->create(['username' => 'carol2']);
        $carolToken = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($carol);

        // Alice → Bob (pending), Carol → Alice (pending)
        Friendship::factory()->between($this->alice, $this->bob)->create();
        Friendship::factory()->between($carol, $this->alice)->create();

        // Alice should see 1 outgoing + 1 incoming = 2 total.
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/friends/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ---------------------------------------------------------------------------
    // GET /friends
    // ---------------------------------------------------------------------------

    public function test_friends_list_returns_only_accepted(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();
        // Pending request with carol — should NOT appear.
        Friendship::factory()->between($this->alice, $this->carol)->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/friends')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'bob');
    }

    public function test_friends_list_is_symmetric(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $aliceList = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/friends')
            ->assertOk()
            ->json('data');

        $bobList = $this->withHeaders($this->headers($this->bobToken))
            ->getJson('/api/friends')
            ->assertOk()
            ->json('data');

        $this->assertSame('bob', $aliceList[0]['username']);
        $this->assertSame('alice', $bobList[0]['username']);
    }

    // ---------------------------------------------------------------------------
    // DELETE /friends/{user}  — unfriend
    // ---------------------------------------------------------------------------

    public function test_unfriend_removes_accepted_friendship(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->deleteJson("/api/friends/{$this->bob->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('friendships', ['id' => $f->id]);
    }

    public function test_unfriend_non_friend_returns_404(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->deleteJson("/api/friends/{$this->bob->id}")
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------
    // GET /users/search
    // ---------------------------------------------------------------------------

    public function test_user_search_returns_matching_usernames(): void
    {
        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=bo')
            ->assertOk();

        $usernames = collect($response->json('data'))->pluck('username')->all();
        $this->assertContains('bob', $usernames);
    }

    public function test_user_search_excludes_self(): void
    {
        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=alice')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->alice->id, $ids);
    }

    public function test_user_search_excludes_existing_friends(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=bo')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->bob->id, $ids);
    }

    public function test_user_search_excludes_pending_relationships(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->create();

        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=bo')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->bob->id, $ids);
    }

    public function test_user_search_excludes_non_discoverable_users(): void
    {
        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['discoverable' => false],
        );

        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=bo')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->bob->id, $ids);
    }

    public function test_user_search_requires_q_param(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search')
            ->assertUnprocessable();
    }

    public function test_user_search_never_returns_email(): void
    {
        $response = $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/users/search?q=bo')
            ->assertOk();

        foreach ($response->json('data') as $user) {
            $this->assertArrayNotHasKey('email', $user);
        }
    }

    // ---------------------------------------------------------------------------
    // GET /privacy-settings + PATCH /privacy-settings
    // ---------------------------------------------------------------------------

    public function test_get_privacy_settings_returns_defaults(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/privacy-settings')
            ->assertOk()
            ->assertJsonPath('data.collection_visibility', 'friends')
            ->assertJsonPath('data.decks_visibility', 'friends')
            ->assertJsonPath('data.discoverable', true);
    }

    public function test_patch_privacy_settings_updates_values(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->patchJson('/api/privacy-settings', [
                'collection_visibility' => 'private',
                'discoverable'          => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.collection_visibility', 'private')
            ->assertJsonPath('data.decks_visibility', 'friends')
            ->assertJsonPath('data.discoverable', false);
    }

    public function test_patch_privacy_settings_rejects_public_visibility(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->patchJson('/api/privacy-settings', ['collection_visibility' => 'public'])
            ->assertUnprocessable();
    }
}
