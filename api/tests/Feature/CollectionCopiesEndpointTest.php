<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionCopiesEndpointTest extends TestCase
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

    public function test_returns_only_current_users_entries(): void
    {
        $card = ScryfallCard::factory()->create();
        $other = User::factory()->create();

        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
        ]);
        CollectionEntry::factory()->create([
            'user_id'     => $other->id,
            'scryfall_id' => $card->scryfall_id,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/collection/copies?scryfall_id={$card->scryfall_id}")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_includes_location_name_and_type(): void
    {
        $card = ScryfallCard::factory()->create();
        $location = Location::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Shelf A',
            'type'    => 'drawer',
        ]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $location->id,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/collection/copies?scryfall_id={$card->scryfall_id}")
            ->assertOk()
            ->assertJsonPath('0.location_name', 'Shelf A')
            ->assertJsonPath('0.location_type', 'drawer');
    }

    public function test_rejects_invalid_uuid(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/collection/copies?scryfall_id=not-a-uuid')
            ->assertStatus(422);
    }
}
