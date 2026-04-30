<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Location;
use App\Models\User;
use App\Services\PendingRelocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationVisibilityTest extends TestCase
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

    public function test_locations_index_hides_auto_managed_rows(): void
    {
        $userDrawer = Location::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Visible Drawer',
        ]);
        Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Hidden Deck',
            'format'  => 'commander',
        ]);
        app(PendingRelocationService::class)->ensureLocation($this->user);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/locations')
            ->assertOk();

        $names = collect($response->json('locations'))->pluck('name')->all();
        $this->assertContains('Visible Drawer', $names);
        $this->assertNotContains('Deck: Hidden Deck', $names);
        $this->assertNotContains('Pending Relocation', $names);
    }

    public function test_location_groups_index_hides_auto_managed_rows(): void
    {
        Location::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Visible Drawer',
        ]);
        Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Hidden Deck',
            'format'  => 'commander',
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/location-groups')
            ->assertOk();

        $items = collect($response->json('items'));
        $names = $items->pluck('name')->all();
        $this->assertContains('Visible Drawer', $names);
        $this->assertNotContains('Deck: Hidden Deck', $names);
    }

    public function test_cannot_update_auto_managed_location(): void
    {
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'My Deck',
            'format'  => 'commander',
        ]);
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->putJson("/api/locations/{$deckLocation->id}", [
                'type' => 'drawer',
                'name' => 'Hijacked',
            ])
            ->assertForbidden();
    }

    public function test_cannot_delete_auto_managed_location(): void
    {
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'My Deck',
            'format'  => 'commander',
        ]);
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->deleteJson("/api/locations/{$deckLocation->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('locations', ['id' => $deckLocation->id]);
    }
}
