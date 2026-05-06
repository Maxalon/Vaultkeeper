<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarMoveTest extends TestCase
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

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_drag_a_deck_into_a_group_persists_on_the_shadow_location(): void
    {
        $group = LocationGroup::create([
            'user_id'    => $this->user->id,
            'name'       => 'Decks',
            'sort_order' => 0,
        ]);
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'My Deck',
            'format'  => 'commander',
        ]);
        $shadow = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'deck',
                'id'        => $shadow->id,
                'parent_id' => $group->id,
                'position'  => 0,
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('locations', [
            'id'         => $shadow->id,
            'group_id'   => $group->id,
            'sort_order' => 0,
        ]);
    }

    public function test_a_drawer_and_a_deck_can_be_freely_interleaved(): void
    {
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'D',
            'format'  => 'commander',
        ]);
        $deckShadow = Location::where('deck_id', $deck->id)->firstOrFail();
        $drawer = Location::factory()->create([
            'user_id'    => $this->user->id,
            'name'       => 'Drawer',
            'sort_order' => 1,
        ]);
        // Both are top-level. Move the drawer above the deck.
        $deckShadow->update(['group_id' => null, 'sort_order' => 0]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'location',
                'id'        => $drawer->id,
                'parent_id' => null,
                'position'  => 0,
            ])
            ->assertNoContent();

        $this->assertSame(0, (int) $drawer->fresh()->sort_order);
        $this->assertSame(1, (int) $deckShadow->fresh()->sort_order);
        $this->assertNull($deckShadow->fresh()->group_id);
        $this->assertNull($drawer->fresh()->group_id);
    }

    public function test_another_users_location_id_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreign = Location::factory()->create(['user_id' => $other->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'location',
                'id'        => $foreign->id,
                'parent_id' => null,
                'position'  => 0,
            ])
            ->assertForbidden();
    }

    public function test_another_users_deck_shadow_id_is_rejected(): void
    {
        $other = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $other->id,
            'name'    => 'Theirs',
            'format'  => 'commander',
        ]);
        $foreignShadow = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'deck',
                'id'        => $foreignShadow->id,
                'parent_id' => null,
                'position'  => 0,
            ])
            ->assertForbidden();
    }

    public function test_another_users_destination_group_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreignGroup = LocationGroup::create([
            'user_id'    => $other->id,
            'name'       => 'Theirs',
            'sort_order' => 0,
        ]);
        $loc = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'location',
                'id'        => $loc->id,
                'parent_id' => $foreignGroup->id,
                'position'  => 0,
            ])
            ->assertStatus(422);
    }

    public function test_dropping_a_location_into_a_nested_group_renumbers_only_affected_parents(): void
    {
        $outer = LocationGroup::create([
            'user_id'    => $this->user->id,
            'name'       => 'Outer',
            'sort_order' => 0,
        ]);
        $inner = LocationGroup::create([
            'user_id'         => $this->user->id,
            'name'            => 'Inner',
            'parent_group_id' => $outer->id,
            'sort_order'      => 0,
        ]);
        $a = Location::factory()->create([
            'user_id'    => $this->user->id,
            'name'       => 'A',
            'group_id'   => $outer->id,
            'sort_order' => 1,
        ]);
        $b = Location::factory()->create([
            'user_id'    => $this->user->id,
            'name'       => 'B',
            'group_id'   => $outer->id,
            'sort_order' => 2,
        ]);

        // Drop A into Inner at position 0.
        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'location',
                'id'        => $a->id,
                'parent_id' => $inner->id,
                'position'  => 0,
            ])
            ->assertNoContent();

        // A is now in Inner at index 0.
        $this->assertDatabaseHas('locations', [
            'id'         => $a->id,
            'group_id'   => $inner->id,
            'sort_order' => 0,
        ]);
        // Outer's remaining children compacted: Inner=0, B=1.
        $this->assertSame(0, (int) $inner->fresh()->sort_order);
        $this->assertSame(1, (int) $b->fresh()->sort_order);
    }

    public function test_a_group_cannot_be_moved_into_its_own_descendant(): void
    {
        $a = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'A', 'sort_order' => 0,
        ]);
        $b = LocationGroup::create([
            'user_id'         => $this->user->id,
            'name'            => 'B',
            'parent_group_id' => $a->id,
            'sort_order'      => 0,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'group',
                'id'        => $a->id,
                'parent_id' => $b->id,
                'position'  => 0,
            ])
            ->assertStatus(422);
    }

    public function test_a_group_cannot_be_its_own_parent(): void
    {
        $g = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'G', 'sort_order' => 0,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'group',
                'id'        => $g->id,
                'parent_id' => $g->id,
                'position'  => 0,
            ])
            ->assertStatus(422);
    }

    public function test_position_past_the_end_is_clamped(): void
    {
        $a = Location::factory()->create([
            'user_id' => $this->user->id, 'name' => 'A', 'sort_order' => 0,
        ]);
        $b = Location::factory()->create([
            'user_id' => $this->user->id, 'name' => 'B', 'sort_order' => 1,
        ]);

        // Move A to position 99 — should land at the end (after B).
        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/move', [
                'kind'      => 'location',
                'id'        => $a->id,
                'parent_id' => null,
                'position'  => 99,
            ])
            ->assertNoContent();

        $this->assertSame(1, (int) $a->fresh()->sort_order);
        $this->assertSame(0, (int) $b->fresh()->sort_order);
    }
}
