<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end search endpoint tests. Hits the window-wrapped oracle-grouping
 * query with real data so we catch SQL issues early.
 */
class ScryfallCardControllerSearchTest extends TestCase
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

    public function test_search_returns_one_row_per_oracle(): void
    {
        $oracleId = '00000000-0000-0000-0000-000000000001';
        // Two printings of the same oracle.
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId,
            'name'      => 'Llanowar Elves',
            'released_at' => '2018-07-13',
            'is_default_eligible' => true,
            'supertypes' => [],
            'types'      => ['Creature'],
            'subtypes'   => ['Elf', 'Druid'],
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId,
            'name'      => 'Llanowar Elves',
            'released_at' => '2024-08-02',
            'is_default_eligible' => true,
            'supertypes' => [],
            'types'      => ['Creature'],
            'subtypes'   => ['Elf', 'Druid'],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?q=llanowar');

        $resp->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame(2, $resp->json('data.0.printing_count'));
    }

    public function test_representative_prefers_owned_printing(): void
    {
        $oracleId = '00000000-0000-0000-0000-000000000002';

        $olderOwned = ScryfallCard::factory()->create([
            'oracle_id'           => $oracleId,
            'name'                => 'Sol Ring',
            'set_code'            => 'old',
            'released_at'         => '1993-12-01',
            'is_default_eligible' => true,
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        ScryfallCard::factory()->create([
            'oracle_id'           => $oracleId,
            'name'                => 'Sol Ring',
            'set_code'            => 'new',
            'released_at'         => '2024-08-02',
            'is_default_eligible' => true,
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        // User owns the older printing.
        CollectionEntry::create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $olderOwned->scryfall_id,
            'quantity'    => 1,
            'condition'   => 'nm',
            'foil'        => false,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?q=sol ring');

        $resp->assertOk();
        $this->assertSame($olderOwned->scryfall_id, $resp->json('data.0.scryfall_id'));
        $this->assertSame(1, $resp->json('data.0.owned_count'));
    }

    public function test_owned_only_filters_to_owned_oracles(): void
    {
        ScryfallCard::factory()->create([
            'name' => 'Never-Owned',
            'oracle_id' => '00000000-0000-0000-0000-000000000010',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        $owned = ScryfallCard::factory()->create([
            'name' => 'Owned Card',
            'oracle_id' => '00000000-0000-0000-0000-000000000011',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        CollectionEntry::create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $owned->scryfall_id,
            'quantity'    => 1,
            'condition'   => 'nm',
            'foil'        => false,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?owned_only=1');

        $resp->assertOk();
        $this->assertSame(1, $resp->json('total'));
        $this->assertSame('Owned Card', $resp->json('data.0.name'));
    }

    public function test_deck_id_for_other_user_404(): void
    {
        $otherUser = User::factory()->create();
        $otherDeck = Deck::create([
            'user_id' => $otherUser->id,
            'name'    => 'Theirs',
            'format'  => 'commander',
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/search?deck_id={$otherDeck->id}");

        $resp->assertNotFound();
    }

    public function test_deck_id_filters_format_and_identity(): void
    {
        // A WU deck; Lightning Bolt (R) should not pass the color filter.
        $deck = Deck::create([
            'user_id'        => $this->user->id,
            'name'           => 'Azorius',
            'format'         => 'commander',
            'color_identity' => 'UW',
        ]);

        ScryfallCard::factory()->create([
            'name'           => 'Lightning Bolt',
            'oracle_id'      => '00000000-0000-0000-0000-000000000020',
            'color_identity' => ['R'],
            'legalities'     => ['commander' => 'legal'],
            'supertypes' => [], 'types' => ['Instant'], 'subtypes' => [],
        ]);
        ScryfallCard::factory()->create([
            'name'           => 'Sol Ring',
            'oracle_id'      => '00000000-0000-0000-0000-000000000021',
            'color_identity' => [],
            'legalities'     => ['commander' => 'legal'],
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/search?deck_id={$deck->id}");

        $resp->assertOk();
        $names = collect($resp->json('data'))->pluck('name')->all();
        $this->assertContains('Sol Ring', $names);
        $this->assertNotContains('Lightning Bolt', $names);
    }

    public function test_apply_identity_false_disables_identity_filter(): void
    {
        $deck = Deck::create([
            'user_id'        => $this->user->id,
            'name'           => 'Azorius',
            'format'         => 'commander',
            'color_identity' => 'UW',
        ]);

        ScryfallCard::factory()->create([
            'name'           => 'Lightning Bolt',
            'oracle_id'      => '00000000-0000-0000-0000-000000000030',
            'color_identity' => ['R'],
            'legalities'     => ['commander' => 'legal'],
            'supertypes' => [], 'types' => ['Instant'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/search?deck_id={$deck->id}&apply_identity=0&q=lightning");

        $resp->assertOk();
        $this->assertSame(1, $resp->json('total'));
    }

    public function test_warnings_at_top_level_for_unsupported_op(): void
    {
        ScryfallCard::factory()->create([
            'name' => 'Any',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?q=art:terese');

        $resp->assertOk();
        $this->assertNotEmpty($resp->json('warnings'));
    }

    public function test_oracle_aggregated_ownership_sums_across_printings(): void
    {
        $oracleId = '00000000-0000-0000-0000-000000000040';
        $a = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Sol Ring', 'set_code' => 'lea',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        $b = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Sol Ring', 'set_code' => 'cmr',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $a->scryfall_id,
            'quantity' => 2, 'condition' => 'nm', 'foil' => false,
        ]);
        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $b->scryfall_id,
            'quantity' => 1, 'condition' => 'nm', 'foil' => false,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?q=sol');

        $resp->assertOk();
        $this->assertSame(3, $resp->json('data.0.owned_count'));
    }

    public function test_wanted_by_others_excludes_current_deck(): void
    {
        $oracleId = '00000000-0000-0000-0000-000000000050';
        $card = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Rampant Growth',
            'supertypes' => [], 'types' => ['Sorcery'], 'subtypes' => [],
        ]);

        $deckX = Deck::create([
            'user_id' => $this->user->id, 'name' => 'X', 'format' => 'commander',
        ]);
        $deckY = Deck::create([
            'user_id' => $this->user->id, 'name' => 'Y', 'format' => 'commander',
        ]);

        DeckEntry::create([
            'deck_id' => $deckX->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 2, 'zone' => 'main',
        ]);
        DeckEntry::create([
            'deck_id' => $deckY->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
        ]);

        // Without deck_id: wanted_by_others = 3 (both decks).
        $resp = $this->withHeaders($this->headers())
            ->getJson('/api/scryfall-cards/search?q=rampant');
        $this->assertSame(3, $resp->json('data.0.wanted_by_others'));

        // With deck_id=X: wanted_by_others = 1 (only Y counts).
        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/search?q=rampant&deck_id={$deckX->id}&apply_format=0&apply_identity=0");
        $this->assertSame(1, $resp->json('data.0.wanted_by_others'));
    }
}
