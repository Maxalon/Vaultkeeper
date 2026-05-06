<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckShadowSortOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_deck_assigns_next_top_level_sort_order_to_its_shadow(): void
    {
        $user = User::factory()->create();

        // Existing items competing for the top-level ordering space.
        Location::factory()->create([
            'user_id'    => $user->id,
            'sort_order' => 5,
        ]);
        LocationGroup::create([
            'user_id'    => $user->id,
            'name'       => 'A group',
            'sort_order' => 7,
        ]);

        $deck = Deck::create([
            'user_id' => $user->id,
            'name'    => 'Newcomer',
            'format'  => 'commander',
        ]);
        $shadow = Location::where('deck_id', $deck->id)->firstOrFail();

        // Should land at max(5, 7) + 1 = 8.
        $this->assertSame(8, (int) $shadow->sort_order);
        $this->assertNull($shadow->group_id);
    }

    public function test_first_deck_for_a_user_gets_sort_order_one(): void
    {
        $user = User::factory()->create();

        $deck = Deck::create([
            'user_id' => $user->id,
            'name'    => 'First',
            'format'  => 'commander',
        ]);
        $shadow = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->assertSame(1, (int) $shadow->sort_order);
    }
}
