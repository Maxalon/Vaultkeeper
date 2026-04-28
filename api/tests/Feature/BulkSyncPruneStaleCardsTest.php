<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\BulkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for BulkSyncService::pruneStaleCards() — the auto-cleanup pass that
 * runs at the end of scryfall:sync-bulk to drop rows untouched for 21+ days
 * (digital-only Alchemy legacy + cards Scryfall has removed).
 */
class BulkSyncPruneStaleCardsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BulkSyncService
    {
        return app(BulkSyncService::class);
    }

    public function test_deletes_rows_older_than_threshold(): void
    {
        ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000001',
            'last_synced_at' => now()->subDays(30),
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000002',
            'last_synced_at' => now()->subDays(5),
        ]);

        $out = $this->service()->pruneStaleCards();

        $this->assertSame(1, $out['deleted']);
        $this->assertSame(0, $out['protected']);
        $this->assertNull(ScryfallCard::where('scryfall_id', '00000000-0000-0000-0000-000000000001')->first());
        $this->assertNotNull(ScryfallCard::where('scryfall_id', '00000000-0000-0000-0000-000000000002')->first());
    }

    public function test_keeps_stale_rows_referenced_by_collection(): void
    {
        $stale = ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000010',
            'last_synced_at' => now()->subDays(60),
        ]);
        CollectionEntry::factory()->create(['scryfall_id' => $stale->scryfall_id]);

        $out = $this->service()->pruneStaleCards();

        $this->assertSame(0, $out['deleted']);
        $this->assertSame(1, $out['protected']);
        $this->assertNotNull(ScryfallCard::where('scryfall_id', $stale->scryfall_id)->first());
    }

    public function test_keeps_stale_rows_referenced_by_deck(): void
    {
        $stale = ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000020',
            'last_synced_at' => now()->subDays(60),
        ]);

        // No DeckEntry factory in this codebase — build the deck graph the
        // same way DeckControllerTest does.
        $user = User::factory()->create();
        $deck = Deck::create(['user_id' => $user->id, 'name' => 'D', 'format' => 'commander']);
        DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $stale->scryfall_id,
            'quantity'    => 1,
            'zone'        => 'main',
        ]);

        $out = $this->service()->pruneStaleCards();

        $this->assertSame(0, $out['deleted']);
        $this->assertSame(1, $out['protected']);
        $this->assertNotNull(ScryfallCard::where('scryfall_id', $stale->scryfall_id)->first());
    }

    public function test_threshold_is_configurable(): void
    {
        ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000030',
            'last_synced_at' => now()->subDays(10),
        ]);

        // 21-day default keeps it.
        $this->assertSame(0, $this->service()->pruneStaleCards()['deleted']);

        // 7-day window catches it.
        $this->assertSame(1, $this->service()->pruneStaleCards(7)['deleted']);
    }

    public function test_default_threshold_is_21_days(): void
    {
        $this->assertSame(21, BulkSyncService::STALE_CARD_THRESHOLD_DAYS);
    }

    public function test_cascades_to_scryfall_cards_raw(): void
    {
        $stale = ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000040',
            'last_synced_at' => now()->subDays(60),
        ]);
        DB::table('scryfall_cards_raw')->insert([
            'scryfall_id' => $stale->scryfall_id,
            'all_parts'   => json_encode([['object' => 'related_card']]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->assertSame(1, DB::table('scryfall_cards_raw')->where('scryfall_id', $stale->scryfall_id)->count());

        $this->service()->pruneStaleCards();

        // FK cascade-delete should have wiped the raw row alongside the parent.
        $this->assertSame(0, DB::table('scryfall_cards_raw')->where('scryfall_id', $stale->scryfall_id)->count());
    }

    public function test_idempotent_when_nothing_stale(): void
    {
        ScryfallCard::factory()->create([
            'scryfall_id'    => '00000000-0000-0000-0000-000000000050',
            'last_synced_at' => now()->subDay(),
        ]);

        $out = $this->service()->pruneStaleCards();
        $this->assertSame(0, $out['deleted']);
        $this->assertSame(0, $out['protected']);
        $this->assertSame(1, ScryfallCard::count());
    }
}
