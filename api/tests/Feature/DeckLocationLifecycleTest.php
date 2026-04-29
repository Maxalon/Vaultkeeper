<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckLocationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_deck_creates_an_auto_managed_deck_location(): void
    {
        $user = User::factory()->create();

        $deck = Deck::create([
            'user_id' => $user->id,
            'name'    => 'Selesnya Tokens',
            'format'  => 'commander',
        ]);

        $this->assertDatabaseHas('locations', [
            'user_id' => $user->id,
            'deck_id' => $deck->id,
            'role'    => Location::ROLE_DECK,
            'name'    => 'Deck: Selesnya Tokens',
        ]);
    }

    public function test_renaming_a_deck_renames_its_deck_location(): void
    {
        $deck = Deck::create([
            'user_id' => User::factory()->create()->id,
            'name'    => 'Old Name',
            'format'  => 'commander',
        ]);

        $deck->update(['name' => 'Brand New']);

        $this->assertDatabaseHas('locations', [
            'deck_id' => $deck->id,
            'role'    => Location::ROLE_DECK,
            'name'    => 'Deck: Brand New',
        ]);
        $this->assertDatabaseMissing('locations', [
            'deck_id' => $deck->id,
            'name'    => 'Deck: Old Name',
        ]);
    }

    public function test_updating_a_deck_without_renaming_does_not_touch_location(): void
    {
        $deck = Deck::create([
            'user_id' => User::factory()->create()->id,
            'name'    => 'Stable',
            'format'  => 'commander',
        ]);

        $location = Location::where('deck_id', $deck->id)->firstOrFail();
        $original = $location->updated_at;

        // Touch the database clock forward then write a non-name change.
        $this->travel(2)->seconds();
        $deck->update(['description' => 'updated description']);

        $this->assertEquals(
            $original->toDateTimeString(),
            $location->fresh()->updated_at->toDateTimeString(),
        );
    }

    public function test_deleting_a_deck_cascades_to_its_deck_location(): void
    {
        $deck = Deck::create([
            'user_id' => User::factory()->create()->id,
            'name'    => 'Doomed',
            'format'  => 'commander',
        ]);

        $locationId = Location::where('deck_id', $deck->id)->value('id');
        $this->assertNotNull($locationId);

        $deck->delete();

        $this->assertDatabaseMissing('locations', ['id' => $locationId]);
    }

    public function test_deck_location_long_name_is_truncated_within_column_limit(): void
    {
        $longName = str_repeat('A', 200);
        $deck = Deck::create([
            'user_id' => User::factory()->create()->id,
            'name'    => $longName,
            'format'  => 'commander',
        ]);

        $location = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->assertLessThanOrEqual(100, strlen($location->name));
        $this->assertStringStartsWith('Deck: ', $location->name);
    }
}
