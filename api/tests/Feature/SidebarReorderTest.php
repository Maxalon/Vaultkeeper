<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarReorderTest extends TestCase
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
            ->postJson('/api/location-groups/reorder', [
                'items' => [[
                    'kind'     => 'group',
                    'id'       => $group->id,
                    'children' => [
                        ['kind' => 'deck', 'id' => $shadow->id],
                    ],
                ]],
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
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/reorder', [
                'items' => [
                    ['kind' => 'deck',     'id' => $deckShadow->id],
                    ['kind' => 'location', 'id' => $drawer->id],
                ],
            ])
            ->assertNoContent();

        $this->assertSame(0, (int) $deckShadow->fresh()->sort_order);
        $this->assertSame(1, (int) $drawer->fresh()->sort_order);
        $this->assertNull($deckShadow->fresh()->group_id);
        $this->assertNull($drawer->fresh()->group_id);
    }

    public function test_another_users_location_id_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreign = Location::factory()->create(['user_id' => $other->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/reorder', [
                'items' => [
                    ['kind' => 'location', 'id' => $foreign->id],
                ],
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
            ->postJson('/api/location-groups/reorder', [
                'items' => [
                    ['kind' => 'deck', 'id' => $foreignShadow->id],
                ],
            ])
            ->assertForbidden();
    }

    public function test_recursive_reorder_writes_nested_group_and_locations_atomically(): void
    {
        $outer = LocationGroup::create([
            'user_id'    => $this->user->id,
            'name'       => 'Outer',
            'sort_order' => 0,
        ]);
        $inner = LocationGroup::create([
            'user_id'    => $this->user->id,
            'name'       => 'Inner',
            'sort_order' => 1,
        ]);
        $loc = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/reorder', [
                'items' => [[
                    'kind'     => 'group',
                    'id'       => $outer->id,
                    'children' => [[
                        'kind'     => 'group',
                        'id'       => $inner->id,
                        'children' => [
                            ['kind' => 'location', 'id' => $loc->id],
                        ],
                    ]],
                ]],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('location_groups', [
            'id'              => $outer->id,
            'parent_group_id' => null,
            'sort_order'      => 0,
        ]);
        $this->assertDatabaseHas('location_groups', [
            'id'              => $inner->id,
            'parent_group_id' => $outer->id,
            'sort_order'      => 0,
        ]);
        $this->assertDatabaseHas('locations', [
            'id'         => $loc->id,
            'group_id'   => $inner->id,
            'sort_order' => 0,
        ]);
    }

    public function test_a_group_appearing_more_than_once_in_the_payload_is_rejected_as_a_cycle(): void
    {
        $a = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'A', 'sort_order' => 0,
        ]);
        $b = LocationGroup::create([
            'user_id' => $this->user->id, 'name' => 'B', 'sort_order' => 1,
        ]);

        // A contains B contains A — A appears twice.
        $this->withHeaders($this->headers())
            ->postJson('/api/location-groups/reorder', [
                'items' => [[
                    'kind'     => 'group',
                    'id'       => $a->id,
                    'children' => [[
                        'kind'     => 'group',
                        'id'       => $b->id,
                        'children' => [
                            ['kind' => 'group', 'id' => $a->id, 'children' => []],
                        ],
                    ]],
                ]],
            ])
            ->assertStatus(422);
    }
}
