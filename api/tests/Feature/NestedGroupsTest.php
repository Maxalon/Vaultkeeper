<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NestedGroupsTest extends TestCase
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

    public function test_creating_a_group_with_a_parent_persists_the_relationship(): void
    {
        $parent = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'Parent', 'sort_order' => 0,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/location-groups', [
                'name'            => 'Child',
                'parent_group_id' => $parent->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('location_groups', [
            'id'              => $response->json('id'),
            'parent_group_id' => $parent->id,
        ]);
    }

    public function test_setting_self_as_parent_is_rejected(): void
    {
        $g = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'G', 'sort_order' => 0,
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/location-groups/{$g->id}", [
                'parent_group_id' => $g->id,
            ])
            ->assertStatus(422);
    }

    public function test_setting_a_descendant_as_parent_is_rejected(): void
    {
        $a = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'A', 'sort_order' => 0,
        ]);
        $b = LocationGroup::create([
            'user_id'         => $this->user->id,
            'parent_group_id' => $a->id,
            'name'            => 'B',
            'sort_order'      => 0,
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/location-groups/{$a->id}", [
                'parent_group_id' => $b->id,
            ])
            ->assertStatus(422);
    }

    public function test_deleting_a_parent_promotes_child_groups_to_top_level(): void
    {
        $parent = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'P', 'sort_order' => 0,
        ]);
        $child = LocationGroup::create([
            'user_id'         => $this->user->id,
            'parent_group_id' => $parent->id,
            'name'            => 'C',
            'sort_order'      => 0,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/location-groups/{$parent->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('location_groups', ['id' => $parent->id]);
        $this->assertDatabaseHas('location_groups', [
            'id'              => $child->id,
            'parent_group_id' => null,
        ]);
    }

    public function test_index_returns_a_recursive_tree(): void
    {
        $outer = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'Outer', 'sort_order' => 0,
        ]);
        $inner = LocationGroup::create([
            'user_id'         => $this->user->id,
            'parent_group_id' => $outer->id,
            'name'            => 'Inner',
            'sort_order'      => 0,
        ]);
        $loc = Location::factory()->create([
            'user_id'    => $this->user->id,
            'group_id'   => $inner->id,
            'sort_order' => 0,
        ]);

        $items = $this->withHeaders($this->headers())
            ->getJson('/api/location-groups')
            ->assertOk()
            ->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('group', $items[0]['kind']);
        $this->assertSame($outer->id, $items[0]['id']);
        $this->assertCount(1, $items[0]['children']);
        $this->assertSame('group', $items[0]['children'][0]['kind']);
        $this->assertSame($inner->id, $items[0]['children'][0]['id']);
        $this->assertCount(1, $items[0]['children'][0]['children']);
        $this->assertSame('location', $items[0]['children'][0]['children'][0]['kind']);
        $this->assertSame($loc->id, $items[0]['children'][0]['children'][0]['id']);
    }
}
