<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Friendship state-machine unit tests (cross-cutting #208 [X2]).
 *
 * Covers all edge cases in the friend-request lifecycle:
 *   - Sending a request to self → 422
 *   - Sending a duplicate request while pending → 409
 *   - Sending a request to an existing accepted friend → 409
 *   - Sending a request after a declined relationship → 409
 *   - Requester cannot accept their own request → 403
 *   - Non-participant cannot cancel/respond → 403/404
 *   - Double-accept is idempotent (second 409) — wrong state
 *   - Declined → re-request is 409 (terminal state)
 *   - Canonical (least,greatest) ordering is enforced regardless of who requests
 */
class FriendshipStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $carol;
    private string $aliceToken;
    private string $bobToken;
    private string $carolToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob   = User::factory()->create(['username' => 'bob']);
        $this->carol = User::factory()->create(['username' => 'carol']);

        $this->aliceToken = auth('api')->login($this->alice);
        $this->bobToken   = auth('api')->login($this->bob);
        $this->carolToken = auth('api')->login($this->carol);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ---------------------------------------------------------------------------
    // Sending requests
    // ---------------------------------------------------------------------------

    public function test_user_cannot_send_friend_request_to_self(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'alice'])
            ->assertUnprocessable();
    }

    public function test_sending_request_to_nonexistent_user_returns_404(): void
    {
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'nobody_xyz_999'])
            ->assertNotFound();
    }

    public function test_duplicate_request_while_pending_returns_409(): void
    {
        // Alice sends to Bob.
        Friendship::factory()->between($this->alice, $this->bob)->create();

        // Alice tries again.
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertConflict();
    }

    public function test_request_to_existing_accepted_friend_returns_409(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertConflict();
    }

    public function test_request_after_decline_returns_409(): void
    {
        // Once declined, the state is terminal — re-requesting returns 409.
        Friendship::factory()->between($this->alice, $this->bob)->declined()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertConflict();
    }

    public function test_canonical_ordering_is_enforced_regardless_of_requester(): void
    {
        // Alice (lower id) sends to Bob.
        $this->withHeaders($this->headers($this->aliceToken))
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertCreated();

        $friendship = Friendship::first();
        $this->assertSame(min($this->alice->id, $this->bob->id), $friendship->user_a_id);
        $this->assertSame(max($this->alice->id, $this->bob->id), $friendship->user_b_id);
        $this->assertSame($this->alice->id, $friendship->requester_id);
    }

    public function test_reverse_request_canonical_ordering_is_enforced(): void
    {
        // Bob sends to Alice — same pair, canonical ordering must be (min, max).
        $this->withHeaders($this->headers($this->bobToken))
            ->postJson('/api/friends/requests', ['username' => 'alice'])
            ->assertCreated();

        $friendship = Friendship::first();
        $this->assertSame(min($this->alice->id, $this->bob->id), $friendship->user_a_id);
        $this->assertSame(max($this->alice->id, $this->bob->id), $friendship->user_b_id);
        $this->assertSame($this->bob->id, $friendship->requester_id);
    }

    // ---------------------------------------------------------------------------
    // Accept / Decline
    // ---------------------------------------------------------------------------

    public function test_requester_cannot_accept_own_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();
        // Alice is the requester.

        $this->withHeaders($this->headers($this->aliceToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'accept'])
            ->assertForbidden();
    }

    public function test_addressee_can_accept_pending_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();
        // Alice is the requester; Bob is the addressee.

        $this->withHeaders($this->headers($this->bobToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('friendships', ['id' => $f->id, 'status' => 'accepted']);
    }

    public function test_addressee_can_decline_pending_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'decline'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->assertDatabaseHas('friendships', ['id' => $f->id, 'status' => 'declined']);
    }

    public function test_third_party_cannot_respond_to_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->carolToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'accept'])
            ->assertNotFound(); // 404 because carol doesn't see this request
    }

    public function test_double_accept_returns_409(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'accept'])
            ->assertConflict();
    }

    public function test_accepting_declined_request_returns_409(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->declined()->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'accept'])
            ->assertConflict();
    }

    public function test_unknown_action_returns_422(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->patchJson("/api/friends/requests/{$f->id}", ['action' => 'banana'])
            ->assertUnprocessable();
    }

    // ---------------------------------------------------------------------------
    // Cancel (requester withdraws)
    // ---------------------------------------------------------------------------

    public function test_requester_can_cancel_pending_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->deleteJson("/api/friends/requests/{$f->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('friendships', ['id' => $f->id]);
    }

    public function test_addressee_cannot_cancel_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->deleteJson("/api/friends/requests/{$f->id}")
            ->assertForbidden();
    }

    public function test_requester_cannot_cancel_already_accepted_request(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->deleteJson("/api/friends/requests/{$f->id}")
            ->assertConflict();
    }

    // ---------------------------------------------------------------------------
    // Unfriend
    // ---------------------------------------------------------------------------

    public function test_either_participant_can_unfriend(): void
    {
        $f = Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->aliceToken))
            ->deleteJson("/api/friends/{$this->bob->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('friendships', ['id' => $f->id]);
    }

    public function test_third_party_cannot_unfriend_others(): void
    {
        Friendship::factory()->between($this->alice, $this->bob)->accepted()->create();

        $this->withHeaders($this->headers($this->carolToken))
            ->deleteJson("/api/friends/{$this->bob->id}")
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------
    // PM carry-forward: declined → re-request edge case (#211 review)
    // ---------------------------------------------------------------------------

    public function test_declined_then_request_from_other_side_also_returns_409(): void
    {
        // Alice → Bob declined. Bob now tries to send a new request to Alice.
        // The row still exists (declined is terminal), so it should 409.
        Friendship::factory()->between($this->alice, $this->bob)->declined()->create();

        $this->withHeaders($this->headers($this->bobToken))
            ->postJson('/api/friends/requests', ['username' => 'alice'])
            ->assertConflict();
    }
}
