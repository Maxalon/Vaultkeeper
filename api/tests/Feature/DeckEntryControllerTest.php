<?php

namespace Tests\Feature;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeckEntryControllerTest extends TestCase
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

    public function test_store_adds_entry_and_returns_card_data(): void
    {
        $card = ScryfallCard::factory()->create(['name' => 'Sol Ring']);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries", [
                'scryfall_id' => $card->scryfall_id,
                'zone'        => 'main',
                'quantity'    => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('zone', 'main')
            ->assertJsonPath('scryfall_card.name', 'Sol Ring');

        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $this->deck->id, 'scryfall_id' => $card->scryfall_id,
        ]);
    }

    public function test_store_with_is_commander_promotes_to_first_empty_slot(): void
    {
        $card = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Human', 'color_identity' => ['R'],
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries", [
                'scryfall_id'  => $card->scryfall_id,
                'is_commander' => true,
            ])
            ->assertCreated();

        $this->assertSame(
            $card->scryfall_id,
            $this->deck->fresh()->commander_1_scryfall_id,
        );
        $this->assertSame('R', $this->deck->fresh()->color_identity);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: string}>
     */
    public static function autoCategoryProvider(): array
    {
        // [oracle_tags_on_card, type_line, expected_category]
        return [
            'oracle tag wins — ramp'            => [['ramp'],        'Artifact',  'ramp'],
            'first matching tag wins'           => [['burn','removal'],'Instant','removal'],
            'no tag match — Battle via type'    => [['unknown'],     'Battle — Siege', 'battle'],
            'no tag match — Planeswalker'       => [[],              'Legendary Planeswalker — Jace', 'planeswalker'],
            'no tag match — Creature'           => [[],              'Creature — Elf', 'creature'],
            'no tag match — Land'               => [[],              'Land',            'land'],
            'no tag match — Instant'            => [[],              'Instant',         'instant'],
            'no tag match — Sorcery'            => [[],              'Sorcery',         'sorcery'],
            'no tag match — Artifact'           => [[],              'Artifact',        'artifact'],
            'no tag match — Enchantment'        => [[],              'Enchantment',     'enchantment'],
        ];
    }

    #[DataProvider('autoCategoryProvider')]
    public function test_auto_category_resolution(array $oracleTags, string $typeLine, string $expected): void
    {
        $card = ScryfallCard::factory()->create([
            'type_line'      => $typeLine,
            'color_identity' => [],
            'colors'         => [],
        ]);
        foreach ($oracleTags as $tag) {
            CardOracleTag::create(['oracle_id' => $card->oracle_id, 'tag' => $tag]);
        }

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries", [
                'scryfall_id' => $card->scryfall_id,
            ])
            ->assertCreated()
            ->assertJsonPath('category', $expected);
    }

    public function test_index_computes_owned_and_available_copies(): void
    {
        $card = ScryfallCard::factory()->create();

        // 3 owned physical copies; 1 committed to another deck; 2 should be available.
        $c1 = CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 3, 'condition' => 'NM', 'foil' => false,
        ]);

        $otherDeck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'Other', 'format' => 'commander',
        ]);
        DeckEntry::create([
            'deck_id' => $otherDeck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main', 'physical_copy_id' => $c1->id,
        ]);
        DeckEntry::create([
            'deck_id' => $this->deck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$this->deck->id}/entries")
            ->assertOk();

        $this->assertSame(3, $response->json('0.owned_copies'));
        $this->assertSame(2, $response->json('0.available_copies'));
    }

    public function test_destroy_commander_entry_clears_slot_and_recomputes_identity(): void
    {
        $commander = ScryfallCard::factory()->create([
            'type_line' => 'Legendary Creature — Elf', 'color_identity' => ['G'],
        ]);
        $this->deck->update(['commander_1_scryfall_id' => $commander->scryfall_id, 'color_identity' => 'G']);
        $entry = DeckEntry::create([
            'deck_id' => $this->deck->id, 'scryfall_id' => $commander->scryfall_id,
            'quantity' => 1, 'zone' => 'main', 'is_commander' => true,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$this->deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $deck = $this->deck->fresh();
        $this->assertNull($deck->commander_1_scryfall_id);
        $this->assertNull($deck->color_identity);
    }

    public function test_entries_require_auth_and_ownership(): void
    {
        $other = User::factory()->create();
        $otherDeck = Deck::create([
            'user_id' => $other->id, 'name' => 'Theirs', 'format' => 'commander',
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/decks/{$otherDeck->id}/entries")
            ->assertForbidden();
    }
}
