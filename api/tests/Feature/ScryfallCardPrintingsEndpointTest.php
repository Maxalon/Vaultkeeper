<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\MtgSet;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScryfallCardPrintingsEndpointTest extends TestCase
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

    public function test_returns_printings_sorted_released_desc(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000001';
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'X', 'set_code' => 'old',
            'released_at' => '1993-12-01',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'X', 'set_code' => 'new',
            'released_at' => '2024-08-02',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");

        $resp->assertOk();
        $codes = collect($resp->json('data'))->pluck('set_code')->all();
        $this->assertSame(['new', 'old'], $codes);
    }

    public function test_ownership_split_by_foil(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000002';
        $card = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Foilable',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 2, 'condition' => 'nm', 'foil' => false,
        ]);
        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'condition' => 'nm', 'foil' => true,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");

        $resp->assertOk();
        $this->assertSame(2, $resp->json('data.0.ownership.nonfoil'));
        $this->assertSame(1, $resp->json('data.0.ownership.foil'));
        $this->assertSame(2, $resp->json('data.0.ownership.available_nonfoil'));
        $this->assertSame(1, $resp->json('data.0.ownership.available_foil'));
    }

    public function test_auth_required(): void
    {
        // setUp() calls auth('api')->login() which leaves a guard-local
        // reference to the user. Clear it so the request hits the guard
        // cold and the middleware rejects.
        auth('api')->logout();
        $this->getJson('/api/scryfall-cards/printings?oracle_id=11111111-0000-0000-0000-000000000003')
            ->assertUnauthorized();
    }

    public function test_in_collection_true_when_user_has_non_deck_ce(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000010';
        $card = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Owned',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        // Plain CE, no location — counts as "in collection".
        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'condition' => 'NM', 'foil' => false,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");
        $resp->assertOk();
        $this->assertTrue($resp->json('data.0.ownership.in_collection'));
    }

    public function test_in_collection_false_when_only_ce_is_in_deck_location(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000011';
        $card = ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'DeckOnly',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);
        // Creating a deck auto-creates its deck-role location.
        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'D', 'format' => 'commander',
        ]);
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();
        // CE lives in the deck-location — should NOT count toward
        // "in collection" (user's intent: ignore deck-located copies).
        CollectionEntry::create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLoc->id,
            'quantity' => 1, 'condition' => 'NM', 'foil' => false,
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");
        $resp->assertOk();
        $this->assertFalse($resp->json('data.0.ownership.in_collection'));
        // But the raw owned counts still see it.
        $this->assertSame(1, $resp->json('data.0.ownership.nonfoil'));
    }

    public function test_in_collection_false_when_no_ce_exists(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000012';
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Unowned',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");
        $resp->assertOk();
        $this->assertFalse($resp->json('data.0.ownership.in_collection'));
    }

    public function test_digital_only_printings_are_filtered(): void
    {
        // Mirrors the Ephara, God of the Polis case: a paper printing
        // and a Pioneer Masters (PIO, digital-only) printing of the same
        // oracle. The picker must not surface the digital one.
        $oracleId = '11111111-0000-0000-0000-000000000020';
        MtgSet::create([
            'scryfall_id' => '33333333-3333-3333-3333-333333333333',
            'code' => 'bng', 'name' => 'Born of the Gods',
            'set_type' => 'expansion', 'digital' => false, 'card_count' => 165,
            'search_uri' => '',
        ]);
        MtgSet::create([
            'scryfall_id' => '44444444-4444-4444-4444-444444444444',
            'code' => 'pio', 'name' => 'Pioneer Masters',
            'set_type' => 'masters', 'digital' => true, 'card_count' => 100,
            'search_uri' => '',
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Ephara', 'set_code' => 'bng',
            'supertypes' => ['Legendary'], 'types' => ['Creature'], 'subtypes' => ['God'],
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Ephara', 'set_code' => 'pio',
            'supertypes' => ['Legendary'], 'types' => ['Creature'], 'subtypes' => ['God'],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");
        $resp->assertOk();
        $codes = collect($resp->json('data'))->pluck('set_code')->all();
        $this->assertSame(['bng'], $codes, 'PIO must be filtered as digital-only');
    }

    public function test_set_name_and_icon_svg_uri_joined(): void
    {
        $oracleId = '11111111-0000-0000-0000-000000000004';
        MtgSet::create([
            'scryfall_id' => '22222222-2222-2222-2222-222222222222',
            'code' => 'tdm', 'name' => 'Tarkir: Dragonstorm',
            'set_type' => 'expansion', 'card_count' => 300,
            'icon_svg_uri' => 'https://svgs.scryfall.io/sets/tdm.svg',
            'search_uri' => '',
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $oracleId, 'name' => 'Y', 'set_code' => 'tdm',
            'supertypes' => [], 'types' => ['Artifact'], 'subtypes' => [],
        ]);

        $resp = $this->withHeaders($this->headers())
            ->getJson("/api/scryfall-cards/printings?oracle_id={$oracleId}");
        $resp->assertOk();
        $this->assertSame('Tarkir: Dragonstorm', $resp->json('data.0.set_name'));
        $this->assertSame('https://svgs.scryfall.io/sets/tdm.svg', $resp->json('data.0.icon_svg_uri'));
    }
}
