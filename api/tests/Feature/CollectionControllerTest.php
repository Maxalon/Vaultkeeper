<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\User;
use App\Models\UserCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_index_returns_only_current_users_entries(): void
    {
        $otherUser = User::factory()->create();

        $mine = CollectionEntry::factory()->create(['user_id' => $this->user->id]);
        CollectionEntry::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame($mine->id, $response->json('0.id'));
    }

    public function test_index_filters_by_location_id(): void
    {
        $location = Location::factory()->create(['user_id' => $this->user->id]);

        $inLocation = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => $location->id,
        ]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => null,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/collection?location_id={$location->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $inLocation->id);
    }

    public function test_index_filters_by_unassigned_location(): void
    {
        $location = Location::factory()->create(['user_id' => $this->user->id]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => $location->id,
        ]);
        $unassigned = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => null,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection?location_id=unassigned')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $unassigned->id);
    }

    public function test_index_search_matches_card_name_substring(): void
    {
        $match = UserCard::factory()->create(['name' => 'Lightning Bolt']);
        $skip  = UserCard::factory()->create(['name' => 'Counterspell']);

        $hit = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $match->scryfall_id,
        ]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $skip->scryfall_id,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection?search=bolt')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $hit->id);
    }

    public function test_show_returns_entry_for_owner(): void
    {
        $entry = CollectionEntry::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/collection/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('id', $entry->id);
    }

    public function test_show_forbids_other_users_entry(): void
    {
        $other = User::factory()->create();
        $entry = CollectionEntry::factory()->create(['user_id' => $other->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/collection/{$entry->id}")
            ->assertForbidden();
    }

    public function test_update_changes_condition_and_notes(): void
    {
        $entry = CollectionEntry::factory()->create([
            'user_id'   => $this->user->id,
            'condition' => 'NM',
            'notes'     => null,
        ]);

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/collection/{$entry->id}", [
                'condition' => 'LP',
                'notes'     => 'edge wear',
            ])
            ->assertOk()
            ->assertJsonPath('condition', 'LP')
            ->assertJsonPath('notes', 'edge wear');

        $this->assertDatabaseHas('collection_entries', [
            'id'        => $entry->id,
            'condition' => 'LP',
            'notes'     => 'edge wear',
        ]);
    }

    public function test_update_rejects_invalid_condition(): void
    {
        $entry = CollectionEntry::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/collection/{$entry->id}", ['condition' => 'PRISTINE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('condition');
    }

    public function test_update_rejects_other_users_location(): void
    {
        $entry = CollectionEntry::factory()->create(['user_id' => $this->user->id]);
        $otherLoc = Location::factory()->create(); // belongs to another user

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/collection/{$entry->id}", ['location_id' => $otherLoc->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_update_forbids_other_users_entry(): void
    {
        $other = User::factory()->create();
        $entry = CollectionEntry::factory()->create(['user_id' => $other->id]);

        $this->withHeaders($this->authHeaders())
            ->patchJson("/api/collection/{$entry->id}", ['condition' => 'LP'])
            ->assertForbidden();
    }

    public function test_destroy_deletes_owner_entry(): void
    {
        $entry = CollectionEntry::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/collection/{$entry->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('collection_entries', ['id' => $entry->id]);
    }

    public function test_destroy_forbids_other_users_entry(): void
    {
        $other = User::factory()->create();
        $entry = CollectionEntry::factory()->create(['user_id' => $other->id]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/collection/{$entry->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('collection_entries', ['id' => $entry->id]);
    }

    public function test_batch_move_updates_only_owner_entries(): void
    {
        $target = Location::factory()->create(['user_id' => $this->user->id]);
        $other  = User::factory()->create();

        $mine1 = CollectionEntry::factory()->create(['user_id' => $this->user->id, 'location_id' => null]);
        $mine2 = CollectionEntry::factory()->create(['user_id' => $this->user->id, 'location_id' => null]);
        $theirs = CollectionEntry::factory()->create(['user_id' => $other->id, 'location_id' => null]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/collection/batch-move', [
                'ids'         => [$mine1->id, $mine2->id, $theirs->id],
                'location_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonPath('moved', 2);

        $this->assertSame($target->id, $mine1->fresh()->location_id);
        $this->assertSame($target->id, $mine2->fresh()->location_id);
        $this->assertNull($theirs->fresh()->location_id);
    }

    public function test_batch_move_to_null_unassigns(): void
    {
        $location = Location::factory()->create(['user_id' => $this->user->id]);
        $entry = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => $location->id,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/collection/batch-move', [
                'ids'         => [$entry->id],
                'location_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('moved', 1);

        $this->assertNull($entry->fresh()->location_id);
    }

    public function test_all_collection_routes_require_auth(): void
    {
        // setUp() logged a user in on the guard singleton — drop it so these
        // unauthenticated requests actually see no user.
        auth('api')->logout();

        $this->getJson('/api/collection')->assertUnauthorized();
        $this->getJson('/api/collection/1')->assertUnauthorized();
        $this->patchJson('/api/collection/1', [])->assertUnauthorized();
        $this->deleteJson('/api/collection/1')->assertUnauthorized();
        $this->postJson('/api/collection/batch-move', [])->assertUnauthorized();
    }
}
