<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckLegalityControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Deck $deck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
        $this->deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'Test', 'format' => 'commander',
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_index_returns_deck_size_illegality_on_empty_deck(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$this->deck->id}/illegalities")
            ->assertOk();

        $sizeIllegality = collect($response->json())->firstWhere('type', 'deck_size');
        $this->assertNotNull($sizeIllegality);
        $this->assertFalse($sizeIllegality['ignored']);
        $this->assertSame(100, $sizeIllegality['expected_count']);
    }

    public function test_ignore_then_index_shows_as_ignored(): void
    {
        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/illegalities/ignore", [
                'illegality_type' => 'deck_size',
                'expected_count'  => 100,
            ])
            ->assertCreated();

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$this->deck->id}/illegalities")
            ->assertOk();

        $sizeIllegality = collect($response->json())->firstWhere('type', 'deck_size');
        $this->assertTrue($sizeIllegality['ignored']);
    }

    public function test_unignore_removes_matching_row(): void
    {
        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/illegalities/ignore", [
                'illegality_type' => 'deck_size',
                'expected_count'  => 100,
            ])->assertCreated();

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/illegalities/unignore", [
                'illegality_type' => 'deck_size',
            ])
            ->assertNoContent();

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$this->deck->id}/illegalities")
            ->assertOk();

        $sizeIllegality = collect($response->json())->firstWhere('type', 'deck_size');
        $this->assertFalse($sizeIllegality['ignored']);
    }

    public function test_ignore_is_idempotent(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->withHeaders($this->headers())
                ->postJson("/api/decks/{$this->deck->id}/illegalities/ignore", [
                    'illegality_type' => 'deck_size',
                    'expected_count'  => 100,
                ])
                ->assertCreated();
        }

        $this->assertDatabaseCount('deck_ignored_illegalities', 1);
    }

    public function test_forbids_other_users_deck(): void
    {
        $other = User::factory()->create();
        $theirs = Deck::create([
            'user_id' => $other->id, 'name' => 'Theirs', 'format' => 'commander',
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$theirs->id}/illegalities")
            ->assertForbidden();
    }
}
