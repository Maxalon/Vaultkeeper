<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Friendship;
use App\Models\Location;
use App\Models\User;
use App\Models\UserPrivacySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Policy tests for friend visibility (cross-cutting #207 [X1]).
 *
 * These tests cover the hardest-to-get-right privacy scenarios.
 * A regression here means one user seeing another user's private data.
 *
 * Scenarios covered:
 *   - Non-friend cannot read collection/decks (403)
 *   - Accepted friend with collection_visibility='friends' CAN read collection
 *   - Accepted friend with collection_visibility='private' CANNOT read collection
 *   - Accepted friend with decks_visibility='friends' CAN read decks
 *   - Accepted friend with decks_visibility='private' CANNOT read decks
 *   - Pending (not-yet-accepted) friendship gives no access
 *   - Declined friendship gives no access
 *   - User can always read their own data
 */
class FriendVisibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private string $aliceToken;
    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob   = User::factory()->create(['username' => 'bob']);

        $this->aliceToken = auth('api')->login($this->alice);
        $this->bobToken   = auth('api')->login($this->bob);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ---------------------------------------------------------------------------
    // /api/users/{user}/collection — friend collection visibility
    // ---------------------------------------------------------------------------

    public function test_non_friend_cannot_read_collection(): void
    {
        // No friendship row at all.
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertForbidden();
    }

    public function test_pending_request_does_not_grant_collection_access(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertForbidden();
    }

    public function test_declined_request_does_not_grant_collection_access(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->declined()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertForbidden();
    }

    public function test_accepted_friend_can_read_collection_when_visibility_is_friends(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['collection_visibility' => 'friends'],
        );

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertOk();
    }

    public function test_accepted_friend_cannot_read_collection_when_visibility_is_private(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['collection_visibility' => 'private'],
        );

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertForbidden();
    }

    public function test_user_can_read_own_collection(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson('/api/collection')
            ->assertOk();
    }

    // ---------------------------------------------------------------------------
    // /api/users/{user}/decks — friend deck visibility
    // ---------------------------------------------------------------------------

    public function test_non_friend_cannot_read_decks(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/decks")
            ->assertForbidden();
    }

    public function test_pending_request_does_not_grant_deck_access(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/decks")
            ->assertForbidden();
    }

    public function test_accepted_friend_can_read_decks_when_visibility_is_friends(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['decks_visibility' => 'friends'],
        );

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/decks")
            ->assertOk();
    }

    public function test_accepted_friend_cannot_read_decks_when_visibility_is_private(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['decks_visibility' => 'private'],
        );

        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/decks")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------
    // Symmetry: Alice can read Bob's data iff Bob can read Alice's data
    // (given matching settings)
    // ---------------------------------------------------------------------------

    public function test_friendship_visibility_is_symmetric(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        // Both default to 'friends' visibility.
        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->alice->id],
            ['collection_visibility' => 'friends', 'decks_visibility' => 'friends'],
        );
        UserPrivacySetting::updateOrCreate(
            ['user_id' => $this->bob->id],
            ['collection_visibility' => 'friends', 'decks_visibility' => 'friends'],
        );

        // Bob reads Alice.
        $this->withHeaders($this->headers($this->bobToken))
            ->getJson("/api/users/{$this->alice->id}/collection")
            ->assertOk();

        // Alice reads Bob.
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->bob->id}/collection")
            ->assertOk();
    }

    // ---------------------------------------------------------------------------
    // User cannot access their own data via the friend endpoints (would bypass
    // the friend-endpoint policy check; the regular /collection is the right path)
    // ---------------------------------------------------------------------------

    public function test_user_cannot_use_friend_collection_endpoint_on_themselves(): void
    {
        // No friendship row with self; policy should return 403 or 404 (not 200).
        $this->withHeaders($this->headers($this->aliceToken))
            ->getJson("/api/users/{$this->alice->id}/collection")
            ->assertStatus(403);
    }
}
