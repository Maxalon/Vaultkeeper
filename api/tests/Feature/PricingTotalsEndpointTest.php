<?php

namespace Tests\Feature;

use App\Models\CardPrice;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the EUR pricing endpoints:
 *   GET /api/collection/totals
 *   GET /api/decks/{deck}/totals
 *   GET /api/prices/status
 *
 * The numbers below verify finish-aware pricing across nonfoil / foil /
 * etched, the missing-price counter for unpriced printings, and that
 * the deck totals correctly split owned vs. missing against the user's
 * collection.
 */
class PricingTotalsEndpointTest extends TestCase
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

    private function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function priceFor(string $scryfallId, ?string $eur, ?string $foil = null, ?string $etched = null): void
    {
        CardPrice::create([
            'scryfall_id' => $scryfallId,
            'eur'         => $eur,
            'eur_foil'    => $foil,
            'eur_etched'  => $etched,
            'captured_on' => now()->toDateString(),
            'updated_at'  => now(),
        ]);
    }

    public function test_collection_totals_sums_finish_aware_prices(): void
    {
        $card = ScryfallCard::factory()->create();
        $this->priceFor($card->scryfall_id, '1.50', '4.00', '8.00');

        // 2x nonfoil → 3.00, 1x foil → 4.00, 1x etched → 8.00.  Total: 15.00.
        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 2, 'foil' => false, 'is_etched' => false,
        ]);
        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'foil' => true, 'is_etched' => false,
        ]);
        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'foil' => false, 'is_etched' => true,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection/totals')
            ->assertOk()
            ->assertJson([
                'total'               => 15.0,
                'card_count'          => 4,
                'missing_price_count' => 0,
            ]);
    }

    public function test_collection_totals_counts_unpriced_copies(): void
    {
        $priced = ScryfallCard::factory()->create();
        $unpriced = ScryfallCard::factory()->create();
        $this->priceFor($priced->scryfall_id, '2.00');
        // No row for $unpriced.

        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $priced->scryfall_id,
            'quantity' => 1,
        ]);
        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $unpriced->scryfall_id,
            'quantity' => 3,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection/totals')
            ->assertOk()
            ->assertJson([
                'total'               => 2.0,
                'card_count'          => 4,
                'missing_price_count' => 3,
            ]);
    }

    public function test_collection_totals_isolates_users(): void
    {
        $card = ScryfallCard::factory()->create();
        $this->priceFor($card->scryfall_id, '5.00');

        CollectionEntry::factory()->create([
            'user_id' => $this->user->id, 'scryfall_id' => $card->scryfall_id, 'quantity' => 1,
        ]);
        $other = User::factory()->create();
        CollectionEntry::factory()->create([
            'user_id' => $other->id, 'scryfall_id' => $card->scryfall_id, 'quantity' => 10,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/collection/totals')
            ->assertOk()
            ->assertJson(['total' => 5.0, 'card_count' => 1]);
    }

    public function test_deck_totals_split_owned_and_missing(): void
    {
        $card = ScryfallCard::factory()->create();
        $this->priceFor($card->scryfall_id, '3.00', '7.00');

        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Test',
            'format'  => 'commander',
        ]);

        // Deck wants 4x — user owns 1x in their collection. Missing 3.
        DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 4,
            'zone'        => 'main',
        ]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 1,
        ]);

        // Unbound entries default to nonfoil pricing.
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/decks/{$deck->id}/totals")
            ->assertOk()
            ->assertJson([
                'total'         => 12.0,
                'owned_total'   => 3.0,
                'missing_total' => 9.0,
            ]);
    }

    public function test_deck_totals_uses_bound_copy_finish(): void
    {
        $card = ScryfallCard::factory()->create();
        $this->priceFor($card->scryfall_id, '3.00', '7.00');

        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'X', 'format' => 'commander',
        ]);
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'quantity'    => 1,
            'foil'        => true,
        ]);
        DeckEntry::create([
            'deck_id'          => $deck->id,
            'scryfall_id'      => $card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        // Bound foil copy → priced at eur_foil (7.00), fully owned.
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/decks/{$deck->id}/totals")
            ->assertOk()
            ->assertJson([
                'total'         => 7.0,
                'owned_total'   => 7.0,
                'missing_total' => 0.0,
            ]);
    }

    public function test_deck_totals_blocks_other_users(): void
    {
        $other = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $other->id, 'name' => 'Z', 'format' => 'commander',
        ]);
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/decks/{$deck->id}/totals")
            ->assertForbidden();
    }

    public function test_prices_status_returns_sync_state(): void
    {
        \App\Models\SyncState::create(['key' => 'prices_last_synced_at', 'value' => '2026-05-06T05:00:00Z']);
        \App\Models\SyncState::create(['key' => 'prices_last_manifest_at', 'value' => '2026-05-06T04:30:00Z']);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/prices/status')
            ->assertOk()
            ->assertJson([
                'last_synced_at'   => '2026-05-06T05:00:00Z',
                'last_manifest_at' => '2026-05-06T04:30:00Z',
                'source'           => 'scryfall-cardmarket',
                'currency'         => 'EUR',
            ]);
    }

    public function test_prices_status_handles_unsynced_state(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/prices/status')
            ->assertOk()
            ->assertJson([
                'last_synced_at'   => null,
                'last_manifest_at' => null,
            ]);
    }
}
