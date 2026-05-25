<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameResultControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function makeDeck(): Deck
    {
        return Deck::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_win_increments_winner_and_losses_increment_losers(): void
    {
        $winner = $this->makeDeck();
        $loser1 = $this->makeDeck();
        $loser2 = $this->makeDeck();

        $this->withHeaders($this->headers())
            ->postJson('/api/game-results', [
                'winner_deck_id' => $winner->id,
                'loser_deck_ids' => [$loser1->id, $loser2->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, $winner->fresh()->wins);
        $this->assertSame(0, $winner->fresh()->losses);
        $this->assertSame(0, $loser1->fresh()->wins);
        $this->assertSame(1, $loser1->fresh()->losses);
        $this->assertSame(0, $loser2->fresh()->wins);
        $this->assertSame(1, $loser2->fresh()->losses);
    }

    public function test_draw_records_losses_only(): void
    {
        $deck1 = $this->makeDeck();
        $deck2 = $this->makeDeck();

        $this->withHeaders($this->headers())
            ->postJson('/api/game-results', [
                'winner_deck_id' => null,
                'loser_deck_ids' => [$deck1->id, $deck2->id],
            ])
            ->assertOk();

        $this->assertSame(0, $deck1->fresh()->wins);
        $this->assertSame(1, $deck1->fresh()->losses);
        $this->assertSame(0, $deck2->fresh()->wins);
        $this->assertSame(1, $deck2->fresh()->losses);
    }

    public function test_winner_excluded_from_losers_even_if_sent_in_both(): void
    {
        $deck = $this->makeDeck();
        $loser = $this->makeDeck();

        $this->withHeaders($this->headers())
            ->postJson('/api/game-results', [
                'winner_deck_id' => $deck->id,
                'loser_deck_ids' => [$deck->id, $loser->id],
            ])
            ->assertOk();

        $fresh = $deck->fresh();
        $this->assertSame(1, $fresh->wins);
        $this->assertSame(0, $fresh->losses);
    }

    public function test_empty_loser_ids_is_valid(): void
    {
        $winner = $this->makeDeck();

        $this->withHeaders($this->headers())
            ->postJson('/api/game-results', [
                'winner_deck_id' => $winner->id,
                'loser_deck_ids' => [],
            ])
            ->assertOk();

        $this->assertSame(1, $winner->fresh()->wins);
    }

    public function test_cannot_record_result_for_another_users_deck(): void
    {
        $other = User::factory()->create();
        $otherDeck = Deck::factory()->create(['user_id' => $other->id]);
        $myDeck = $this->makeDeck();

        $this->withHeaders($this->headers())
            ->postJson('/api/game-results', [
                'winner_deck_id' => $myDeck->id,
                'loser_deck_ids' => [$otherDeck->id],
            ])
            ->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $deck = $this->makeDeck();

        $this->postJson('/api/game-results', [
            'winner_deck_id' => $deck->id,
            'loser_deck_ids' => [],
        ])
        ->assertUnauthorized();
    }

    public function test_wins_and_losses_appear_in_deck_detail_response(): void
    {
        $deck = $this->makeDeck();
        Deck::where('id', $deck->id)->update(['wins' => 3, 'losses' => 1]);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$deck->id}")
            ->assertOk()
            ->assertJsonPath('wins', 3)
            ->assertJsonPath('losses', 1);
    }

    public function test_wins_and_losses_appear_in_deck_index_response(): void
    {
        $deck = $this->makeDeck();
        Deck::where('id', $deck->id)->update(['wins' => 2, 'losses' => 4]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/decks')
            ->assertOk();

        $found = collect($response->json())
            ->firstWhere('id', $deck->id);

        $this->assertSame(2, $found['wins']);
        $this->assertSame(4, $found['losses']);
    }
}
