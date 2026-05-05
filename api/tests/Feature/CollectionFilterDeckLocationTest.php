<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per locked decision 4: copies sitting in a deck-location are owned by
 * that deck — they shouldn't appear in the user's general collection
 * views or the deck-binding picker.
 */
class CollectionFilterDeckLocationTest extends TestCase
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

    public function test_collection_index_hides_deck_location_entries(): void
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Hidden',
            'format'  => 'commander',
        ]);
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();
        $binder = Location::factory()->create(['user_id' => $this->user->id]);

        // One CE in the deck-location (should be hidden) and one in a
        // user-managed binder (should appear).
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLocation->id,
        ]);
        $visible = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $binder->id,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/collection')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertCount(1, $ids, 'deck-location CE should not be returned');
    }

    public function test_copies_for_card_hides_deck_location_entries(): void
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Hidden Source',
            'format'  => 'commander',
        ]);
        $deckLocation = Location::where('deck_id', $deck->id)->firstOrFail();
        $binder = Location::factory()->create(['user_id' => $this->user->id]);

        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLocation->id,
        ]);
        $visible = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $binder->id,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/collection/copies?scryfall_id='.$card->scryfall_id)
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertSame([$visible->id], $ids);
    }
}
