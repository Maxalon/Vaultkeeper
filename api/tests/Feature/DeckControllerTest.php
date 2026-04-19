<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckControllerTest extends TestCase
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

    public function test_store_creates_deck_with_commander_and_computes_color_identity(): void
    {
        $commander = ScryfallCard::factory()->create([
            'type_line'      => 'Legendary Creature — Human',
            'color_identity' => ['G', 'W'],
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/decks', [
                'name'                    => 'Selesnya Tokens',
                'format'                  => 'commander',
                'commander_1_scryfall_id' => $commander->scryfall_id,
            ])
            ->assertCreated()
            ->assertJsonPath('color_identity', 'WG')
            ->assertJsonPath('commander1.scryfall_id', $commander->scryfall_id);

        $this->assertDatabaseHas('deck_entries', [
            'scryfall_id'  => $commander->scryfall_id,
            'is_commander' => true,
            'zone'         => 'main',
        ]);
    }

    public function test_index_scopes_to_owner_and_includes_counts(): void
    {
        $other = User::factory()->create();
        Deck::create(['user_id' => $other->id, 'name' => 'Other', 'format' => 'commander']);

        $mine = Deck::create(['user_id' => $this->user->id, 'name' => 'Mine', 'format' => 'commander']);
        $card = ScryfallCard::factory()->create();
        DeckEntry::create([
            'deck_id' => $mine->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 3, 'zone' => 'main',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/decks')->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame($mine->id, $response->json('0.id'));
        $this->assertSame(3, $response->json('0.entry_count'));
    }

    public function test_update_replacing_commander_clears_old_is_commander_flag(): void
    {
        $c1 = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Human', 'color_identity' => ['W'],
        ]);
        $c2 = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Elf', 'color_identity' => ['G'],
        ]);

        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'X', 'format' => 'commander',
            'commander_1_scryfall_id' => $c1->scryfall_id,
        ]);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $c1->scryfall_id,
            'quantity' => 1, 'zone' => 'main', 'is_commander' => true,
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/decks/{$deck->id}", ['commander_1_scryfall_id' => $c2->scryfall_id])
            ->assertOk()
            ->assertJsonPath('color_identity', 'G');

        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deck->id, 'scryfall_id' => $c1->scryfall_id, 'is_commander' => false,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deck->id, 'scryfall_id' => $c2->scryfall_id, 'is_commander' => true,
        ]);
    }

    public function test_destroy_cascades_entries(): void
    {
        $deck = Deck::create(['user_id' => $this->user->id, 'name' => 'Gone', 'format' => 'standard']);
        $card = ScryfallCard::factory()->create();
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('decks', ['id' => $deck->id]);
        $this->assertDatabaseMissing('deck_entries', ['deck_id' => $deck->id]);
    }

    public function test_show_forbids_other_users_deck(): void
    {
        $other = User::factory()->create();
        $deck = Deck::create(['user_id' => $other->id, 'name' => 'Theirs', 'format' => 'standard']);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}")
            ->assertForbidden();
    }

    public function test_all_deck_routes_require_auth(): void
    {
        auth('api')->logout();
        $this->getJson('/api/decks')->assertUnauthorized();
        $this->postJson('/api/decks', [])->assertUnauthorized();
        $this->getJson('/api/decks/1')->assertUnauthorized();
    }
}
