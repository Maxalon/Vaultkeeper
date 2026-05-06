<?php

namespace Tests\Unit;

use App\Models\CardPrice;
use App\Models\CardPriceHistory;
use App\Models\ScryfallCard;
use App\Services\PriceUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PriceUpsertService();
    }

    public function test_build_row_returns_null_when_all_eur_columns_are_null(): void
    {
        $row = $this->service->buildRow([
            'id'     => '00000000-0000-0000-0000-000000000001',
            'prices' => ['eur' => null, 'eur_foil' => null, 'eur_etched' => null,
                          'usd' => '1.00'],
        ], now());
        $this->assertNull($row);
    }

    public function test_build_row_normalises_string_prices(): void
    {
        $row = $this->service->buildRow([
            'id'     => '00000000-0000-0000-0000-000000000002',
            'prices' => ['eur' => '1.234', 'eur_foil' => '', 'eur_etched' => null],
        ], now());

        $this->assertNotNull($row);
        $this->assertSame('1.23', $row['eur']);
        $this->assertNull($row['eur_foil']);
        $this->assertNull($row['eur_etched']);
    }

    public function test_history_deltas_skip_unchanged_prices_on_second_run(): void
    {
        $card = ScryfallCard::factory()->create();
        CardPrice::create([
            'scryfall_id' => $card->scryfall_id,
            'eur'         => '2.00',
            'eur_foil'    => '5.00',
            'eur_etched'  => null,
            'captured_on' => now()->toDateString(),
            'updated_at'  => now(),
        ]);

        $first = $this->service->recordHistoryDeltas();
        $this->assertSame(2, $first); // baseline rows for nonfoil + foil

        // Second run with the same prices on the same captured_on.
        $second = $this->service->recordHistoryDeltas();
        $this->assertSame(0, $second);

        $this->assertSame(2, CardPriceHistory::count());
    }

    public function test_history_records_a_delta_when_price_changes(): void
    {
        $card = ScryfallCard::factory()->create();
        CardPrice::create([
            'scryfall_id' => $card->scryfall_id,
            'eur'         => '2.00',
            'captured_on' => now()->subDay()->toDateString(),
            'updated_at'  => now()->subDay(),
        ]);
        $this->service->recordHistoryDeltas();

        // Price changed today.
        CardPrice::where('scryfall_id', $card->scryfall_id)->update([
            'eur'         => '3.00',
            'captured_on' => now()->toDateString(),
            'updated_at'  => now(),
        ]);
        $delta = $this->service->recordHistoryDeltas();
        $this->assertSame(1, $delta);

        $rows = CardPriceHistory::where('scryfall_id', $card->scryfall_id)
            ->where('finish', 'nonfoil')
            ->orderBy('captured_on')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2.00', (string) $rows[0]->price);
        $this->assertSame('3.00', (string) $rows[1]->price);
    }

    public function test_prune_drops_rows_past_retention(): void
    {
        $card = ScryfallCard::factory()->create();
        CardPriceHistory::create([
            'scryfall_id' => $card->scryfall_id,
            'captured_on' => now()->subDays(91)->toDateString(),
            'finish'      => 'nonfoil',
            'price'       => '1.00',
        ]);
        CardPriceHistory::create([
            'scryfall_id' => $card->scryfall_id,
            'captured_on' => now()->subDays(10)->toDateString(),
            'finish'      => 'nonfoil',
            'price'       => '1.00',
        ]);

        $deleted = $this->service->pruneOldHistory();
        $this->assertSame(1, $deleted);
        $this->assertSame(1, CardPriceHistory::count());
    }
}
