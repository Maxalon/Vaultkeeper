<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\DeckLegalityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckCompanionTest extends TestCase
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

    public function test_creates_deck_with_companion(): void
    {
        $cmdr = ScryfallCard::factory()->create([
            'type_line'      => 'Legendary Creature — Human',
            'color_identity' => ['W'],
        ]);
        $companion = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Dog',
            'keywords'  => ['Companion'],
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/decks', [
                'name'                    => 'Mono-White',
                'format'                  => 'commander',
                'commander_1_scryfall_id' => $cmdr->scryfall_id,
                'companion_scryfall_id'   => $companion->scryfall_id,
            ])
            ->assertCreated()
            ->assertJsonPath('companion.scryfall_id', $companion->scryfall_id)
            ->assertJsonPath('companion_scryfall_id',  $companion->scryfall_id);
    }

    public function test_updates_companion_preserves_commanders(): void
    {
        $cmdr = ScryfallCard::factory()->create([
            'type_line'      => 'Legendary Creature — Human',
            'color_identity' => ['W'],
        ]);
        $companion = ScryfallCard::factory()->create([
            'keywords'  => ['Companion'],
        ]);

        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
            'commander_1_scryfall_id' => $cmdr->scryfall_id,
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/decks/{$deck->id}", [
                'companion_scryfall_id' => $companion->scryfall_id,
            ])
            ->assertOk()
            ->assertJsonPath('companion.scryfall_id', $companion->scryfall_id)
            ->assertJsonPath('commander1.scryfall_id', $cmdr->scryfall_id);
    }

    public function test_invalid_companion_when_not_in_deck(): void
    {
        $cmdr = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Human',
        ]);
        $companion = ScryfallCard::factory()->create([
            'keywords' => ['Companion'],
        ]);

        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
            'commander_1_scryfall_id' => $cmdr->scryfall_id,
            'companion_scryfall_id'   => $companion->scryfall_id,
        ]);

        $service = app(DeckLegalityService::class);
        $illegalities = $service->check($deck);

        $types = array_column($illegalities, 'type');
        $this->assertContains('invalid_companion', $types);
    }

    public function test_invalid_companion_when_missing_keyword(): void
    {
        $cmdr = ScryfallCard::factory()->create(['type_line' => 'Legendary Creature — Human']);
        $notCompanion = ScryfallCard::factory()->create(['keywords' => []]);

        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
            'commander_1_scryfall_id' => $cmdr->scryfall_id,
            'companion_scryfall_id'   => $notCompanion->scryfall_id,
        ]);
        DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $notCompanion->scryfall_id,
            'zone'        => 'main',
            'quantity'    => 1,
        ]);

        $service = app(DeckLegalityService::class);
        $illegalities = $service->check($deck);
        $types = array_column($illegalities, 'type');
        $this->assertContains('invalid_companion', $types);
    }

    public function test_companion_is_valid_when_in_main_with_keyword(): void
    {
        $cmdr = ScryfallCard::factory()->create(['type_line' => 'Legendary Creature — Human']);
        $companion = ScryfallCard::factory()->create(['keywords' => ['Companion']]);

        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
            'commander_1_scryfall_id' => $cmdr->scryfall_id,
            'companion_scryfall_id'   => $companion->scryfall_id,
        ]);
        DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $companion->scryfall_id,
            'zone'        => 'main',
            'quantity'    => 1,
        ]);

        $service = app(DeckLegalityService::class);
        $illegalities = $service->check($deck);
        $types = array_column($illegalities, 'type');
        $this->assertNotContains('invalid_companion', $types);
    }
}
