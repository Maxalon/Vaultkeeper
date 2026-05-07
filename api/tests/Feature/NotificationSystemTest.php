<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Deck;
use App\Models\Friendship;
use App\Models\Location;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for A5 — Notification System.
 *
 * Covers:
 *   1.  friend.request_received notification fires when a request is sent
 *   2.  friend.request_accepted notification fires when a request is accepted
 *   3.  No notification fires on decline
 *   4.  GET /notifications — list, pagination, unread filter
 *   5.  POST /notifications/{id}/read — mark read (idempotent)
 *   6.  POST /notifications/read-all — marks all unread
 *   7.  Staleness: version bump makes action available=false
 *   8.  Action gateway — stale action returns 409
 *   9.  Action gateway — fresh action executes (friend accept via gateway)
 *   10. Action gateway — 404 on unknown key
 *   11. deck.card_marked_for_review notification fires on deck deletion
 *       with assembled copies
 *   12. Notification belongs to recipient only (other user cannot read it)
 */
class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['username' => 'alice']);
        $this->bob   = User::factory()->create(['username' => 'bob']);
    }

    // -------------------------------------------------------------------------
    // 1. friend.request_received
    // -------------------------------------------------------------------------

    public function test_sending_friend_request_creates_notification_for_addressee(): void
    {
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->bob->id,
            'type'    => 'friend.request_received',
        ]);

        $notification = AppNotification::where('user_id', $this->bob->id)
            ->where('type', 'friend.request_received')
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals($this->alice->id, $notification->payload['requester_id']);
        $this->assertEquals('alice', $notification->payload['requester_username']);

        // Actions should include accept + decline, both with invalidates_on
        $this->assertCount(2, $notification->actions);
        $this->assertEquals('accept', $notification->actions[0]['key']);
        $this->assertEquals('decline', $notification->actions[1]['key']);
        $this->assertArrayHasKey('invalidates_on', $notification->actions[0]);
        $this->assertArrayHasKey('version', $notification->actions[0]['invalidates_on'][0]);
    }

    // -------------------------------------------------------------------------
    // 2. friend.request_accepted
    // -------------------------------------------------------------------------

    public function test_accepting_friend_request_creates_notification_for_requester(): void
    {
        // Alice sends request
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $friendship = Friendship::first();

        // Bob accepts
        $this->actingAs($this->bob, 'api')
            ->patchJson("/api/friends/requests/{$friendship->id}", ['action' => 'accept'])
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->alice->id,
            'type'    => 'friend.request_accepted',
        ]);

        $notification = AppNotification::where('user_id', $this->alice->id)
            ->where('type', 'friend.request_accepted')
            ->first();

        $this->assertEquals($this->bob->id, $notification->payload['accepter_id']);
        $this->assertEquals('bob', $notification->payload['accepter_username']);
        // Accepted notification has no actions (informational only).
        $this->assertNull($notification->actions);
    }

    // -------------------------------------------------------------------------
    // 3. No notification on decline
    // -------------------------------------------------------------------------

    public function test_declining_friend_request_does_not_create_accepted_notification(): void
    {
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $friendship = Friendship::first();

        $this->actingAs($this->bob, 'api')
            ->patchJson("/api/friends/requests/{$friendship->id}", ['action' => 'decline'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->alice->id,
            'type'    => 'friend.request_accepted',
        ]);
    }

    // -------------------------------------------------------------------------
    // 4. GET /notifications — listing, unread filter
    // -------------------------------------------------------------------------

    public function test_get_notifications_returns_users_notifications(): void
    {
        // Create one notification for alice, one for bob.
        AppNotification::create([
            'user_id' => $this->alice->id,
            'type'    => 'test.type',
            'payload' => ['foo' => 'bar'],
        ]);
        AppNotification::create([
            'user_id' => $this->bob->id,
            'type'    => 'test.type',
            'payload' => ['foo' => 'baz'],
        ]);

        $this->actingAs($this->alice, 'api')
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'test.type');
    }

    public function test_unread_filter_returns_only_unread(): void
    {
        AppNotification::create([
            'user_id' => $this->alice->id,
            'type'    => 'test.unread',
            'payload' => [],
        ]);
        AppNotification::create([
            'user_id'  => $this->alice->id,
            'type'     => 'test.read',
            'payload'  => [],
            'read_at'  => now(),
        ]);

        $this->actingAs($this->alice, 'api')
            ->getJson('/api/notifications?unread=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'test.unread');
    }

    public function test_notifications_response_includes_meta_pagination(): void
    {
        AppNotification::create(['user_id' => $this->alice->id, 'type' => 'x', 'payload' => []]);

        $response = $this->actingAs($this->alice, 'api')
            ->getJson('/api/notifications')
            ->assertStatus(200);

        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
    }

    // -------------------------------------------------------------------------
    // 5. POST /notifications/{id}/read
    // -------------------------------------------------------------------------

    public function test_mark_read_sets_read_at_timestamp(): void
    {
        $n = AppNotification::create([
            'user_id' => $this->alice->id,
            'type'    => 'test',
            'payload' => [],
        ]);

        $this->assertNull($n->read_at);

        $this->actingAs($this->alice, 'api')
            ->postJson("/api/notifications/{$n->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $n->id);

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_mark_read_is_idempotent(): void
    {
        $n = AppNotification::create([
            'user_id' => $this->alice->id,
            'type'    => 'test',
            'payload' => [],
            'read_at' => now(),
        ]);

        $this->actingAs($this->alice, 'api')
            ->postJson("/api/notifications/{$n->id}/read")
            ->assertStatus(200);
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $n = AppNotification::create([
            'user_id' => $this->bob->id,
            'type'    => 'test',
            'payload' => [],
        ]);

        $this->actingAs($this->alice, 'api')
            ->postJson("/api/notifications/{$n->id}/read")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // 6. POST /notifications/read-all
    // -------------------------------------------------------------------------

    public function test_read_all_marks_all_unread_for_user(): void
    {
        AppNotification::create(['user_id' => $this->alice->id, 'type' => 'a', 'payload' => []]);
        AppNotification::create(['user_id' => $this->alice->id, 'type' => 'b', 'payload' => []]);
        // Bob's notification — must NOT be marked.
        AppNotification::create(['user_id' => $this->bob->id, 'type' => 'c', 'payload' => []]);

        $this->actingAs($this->alice, 'api')
            ->postJson('/api/notifications/read-all')
            ->assertStatus(200)
            ->assertJsonPath('marked_read', 2);

        $this->assertEquals(0, AppNotification::where('user_id', $this->alice->id)->whereNull('read_at')->count());
        // Bob's is still unread.
        $this->assertEquals(1, AppNotification::where('user_id', $this->bob->id)->whereNull('read_at')->count());
    }

    // -------------------------------------------------------------------------
    // 7. Staleness — version bump makes action available=false
    // -------------------------------------------------------------------------

    public function test_action_available_is_false_after_version_bump(): void
    {
        // Alice sends a request so a Friendship row exists.
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $friendship = Friendship::first();

        $notification = AppNotification::where('user_id', $this->bob->id)
            ->where('type', 'friend.request_received')
            ->first();

        // Before mutation — should be available.
        $acceptAction = $notification->findAction('accept');
        $this->assertTrue(NotificationService::isActionAvailable($acceptAction));

        // Mutate the friendship (bump version by touching it).
        $friendship->touch(); // triggers HasOptimisticVersion saving hook → version++

        // After mutation — should be stale.
        $this->assertFalse(NotificationService::isActionAvailable($acceptAction));
    }

    public function test_get_notifications_returns_available_false_on_stale_action(): void
    {
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $friendship = Friendship::first();
        $friendship->touch(); // bump version

        $response = $this->actingAs($this->bob, 'api')
            ->getJson('/api/notifications')
            ->assertStatus(200);

        $actions = $response->json('data.0.actions');
        $this->assertNotEmpty($actions);
        foreach ($actions as $action) {
            $this->assertFalse($action['available']);
        }
    }

    // -------------------------------------------------------------------------
    // 8. Action gateway — stale → 409
    // -------------------------------------------------------------------------

    public function test_execute_action_returns_409_when_stale(): void
    {
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $friendship = Friendship::first();
        $notification = AppNotification::where('user_id', $this->bob->id)->first();

        // Bump version to make the action stale.
        $friendship->touch();

        $this->actingAs($this->bob, 'api')
            ->postJson("/api/notifications/{$notification->id}/actions/accept")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'record_mutated');
    }

    // -------------------------------------------------------------------------
    // 9. Action gateway — fresh action executes
    // -------------------------------------------------------------------------

    public function test_execute_action_accepts_friend_request_when_fresh(): void
    {
        $this->actingAs($this->alice, 'api')
            ->postJson('/api/friends/requests', ['username' => 'bob'])
            ->assertStatus(201);

        $notification = AppNotification::where('user_id', $this->bob->id)
            ->where('type', 'friend.request_received')
            ->first();

        // Execute the accept action through the gateway.
        $this->actingAs($this->bob, 'api')
            ->postJson("/api/notifications/{$notification->id}/actions/accept")
            ->assertSuccessful();

        $this->assertDatabaseHas('friendships', ['status' => 'accepted']);
    }

    // -------------------------------------------------------------------------
    // 10. Action gateway — 404 on unknown key
    // -------------------------------------------------------------------------

    public function test_execute_action_returns_404_for_unknown_key(): void
    {
        $n = AppNotification::create([
            'user_id' => $this->alice->id,
            'type'    => 'test',
            'payload' => [],
            'actions' => [['key' => 'do_something', 'invalidates_on' => []]],
        ]);

        $this->actingAs($this->alice, 'api')
            ->postJson("/api/notifications/{$n->id}/actions/nonexistent_key")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // 11. deck.card_marked_for_review on deck deletion
    // -------------------------------------------------------------------------

    public function test_deck_deletion_with_assembled_copies_fires_review_notification(): void
    {
        $deck = Deck::factory()->create(['user_id' => $this->alice->id]);

        // Find the deck's shadow location (created by DeckObserver::created).
        $deckLocation = Location::where('deck_id', $deck->id)
            ->where('role', 'deck')
            ->firstOrFail();

        // Plant a collection entry in the deck location (simulates assembled copy).
        \App\Models\CollectionEntry::factory()->create([
            'user_id'     => $this->alice->id,
            'location_id' => $deckLocation->id,
        ]);

        $this->actingAs($this->alice, 'api')
            ->deleteJson("/api/decks/{$deck->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->alice->id,
            'type'    => 'deck.card_marked_for_review',
        ]);

        $n = AppNotification::where('user_id', $this->alice->id)
            ->where('type', 'deck.card_marked_for_review')
            ->first();

        $this->assertNotNull($n);
        $this->assertEquals($deck->name, $n->payload['deck_name']);
        $this->assertEquals(1, $n->payload['copies_count']);
    }

    public function test_deck_deletion_without_assembled_copies_does_not_fire_notification(): void
    {
        $deck = Deck::factory()->create(['user_id' => $this->alice->id]);

        $this->actingAs($this->alice, 'api')
            ->deleteJson("/api/decks/{$deck->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->alice->id,
            'type'    => 'deck.card_marked_for_review',
        ]);
    }
}
