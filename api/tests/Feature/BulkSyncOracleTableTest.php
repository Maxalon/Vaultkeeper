<?php

namespace Tests\Feature;

use App\Models\MtgSet;
use App\Models\ScryfallCard;
use App\Models\ScryfallOracle;
use App\Services\BulkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema-touching tests for BulkSyncService::syncOracleTable() — the method
 * that rebuilds scryfall_oracles from scryfall_cards at the end of
 * scryfall:sync-bulk. See issue #30 for design.
 */
class BulkSyncOracleTableTest extends TestCase
{
    use RefreshDatabase;

    private function rebuild(): int
    {
        $out = app(BulkSyncService::class)->syncOracleTable();
        return $out['oracles'];
    }

    public function test_one_row_per_oracle(): void
    {
        $oracleA = '00000000-0000-0000-0000-000000000001';
        $oracleB = '00000000-0000-0000-0000-000000000002';
        ScryfallCard::factory()->create(['oracle_id' => $oracleA, 'name' => 'A', 'set_code' => 'set1']);
        ScryfallCard::factory()->create(['oracle_id' => $oracleA, 'name' => 'A', 'set_code' => 'set2']);
        ScryfallCard::factory()->create(['oracle_id' => $oracleB, 'name' => 'B', 'set_code' => 'set1']);

        $this->assertSame(2, $this->rebuild());
        $this->assertSame(2, ScryfallOracle::count());
        $this->assertSame(2, ScryfallOracle::find($oracleA)->printing_count);
        $this->assertSame(1, ScryfallOracle::find($oracleB)->printing_count);
    }

    public function test_idempotent_rebuild(): void
    {
        ScryfallCard::factory()->create([
            'oracle_id' => '00000000-0000-0000-0000-000000000010',
            'name'      => 'X',
        ]);

        $first = $this->rebuild();
        $second = $this->rebuild();

        $this->assertSame($first, $second);
        $this->assertSame(1, ScryfallOracle::count());
    }

    public function test_color_identity_bits_populated(): void
    {
        $cases = [
            ['id' => '00000000-0000-0000-0000-000000000020', 'ci' => ['G', 'W'],           'bits' => 17],
            ['id' => '00000000-0000-0000-0000-000000000021', 'ci' => [],                   'bits' => 0],
            ['id' => '00000000-0000-0000-0000-000000000022', 'ci' => ['W', 'U', 'B'],      'bits' => 7],
            ['id' => '00000000-0000-0000-0000-000000000023', 'ci' => ['W', 'U', 'B', 'R', 'G'], 'bits' => 31],
        ];

        foreach ($cases as $c) {
            ScryfallCard::factory()->create([
                'oracle_id'      => $c['id'],
                'name'           => "CI-{$c['bits']}",
                'color_identity' => $c['ci'],
            ]);
        }

        $this->rebuild();

        foreach ($cases as $c) {
            $row = ScryfallOracle::find($c['id']);
            $this->assertSame(
                $c['bits'],
                (int) $row->color_identity_bits,
                "color_identity_bits mismatch for identity " . implode('', $c['ci']),
            );
        }
    }

    public function test_representative_picker_prefers_default_eligible(): void
    {
        $oid = '00000000-0000-0000-0000-000000000030';
        $eligible = ScryfallCard::factory()->create([
            'oracle_id'           => $oid,
            'name'                => 'Pick Me',
            'set_code'            => 'aaa',
            'released_at'         => '2020-01-01',
            'is_default_eligible' => true,
            'promo'               => false,
        ]);
        ScryfallCard::factory()->create([
            'oracle_id'           => $oid,
            'name'                => 'Pick Me',
            'set_code'            => 'bbb',
            'released_at'         => '2024-01-01',
            'is_default_eligible' => false,  // newer but not eligible
            'promo'               => false,
        ]);

        $this->rebuild();

        $row = ScryfallOracle::find($oid);
        $this->assertSame($eligible->scryfall_id, $row->default_scryfall_id);
    }

    public function test_representative_picker_non_promo_wins_within_tier(): void
    {
        $oid = '00000000-0000-0000-0000-000000000031';
        $nonPromo = ScryfallCard::factory()->create([
            'oracle_id'           => $oid,
            'name'                => 'Non-Promo',
            'set_code'            => 'zzz',     // later alphabet
            'released_at'         => '2020-01-01',
            'is_default_eligible' => false,
            'promo'               => false,
        ]);
        ScryfallCard::factory()->create([
            'oracle_id'           => $oid,
            'name'                => 'Non-Promo',
            'set_code'            => 'aaa',     // earlier alphabet
            'released_at'         => '2024-01-01',
            'is_default_eligible' => false,
            'promo'               => true,
        ]);

        $this->rebuild();

        // Non-promo beats promo regardless of set alphabet or release date.
        $row = ScryfallOracle::find($oid);
        $this->assertSame($nonPromo->scryfall_id, $row->default_scryfall_id);
    }

    public function test_is_playtest_any_rolls_up(): void
    {
        $oid = '00000000-0000-0000-0000-000000000040';
        ScryfallCard::factory()->create([
            'oracle_id'   => $oid, 'name' => 'pt',
            'is_playtest' => false,
        ]);
        ScryfallCard::factory()->create([
            'oracle_id'   => $oid, 'name' => 'pt',
            'is_playtest' => true,
        ]);

        $this->rebuild();

        $this->assertTrue((bool) ScryfallOracle::find($oid)->is_playtest_any);
    }

    public function test_excluded_from_catalog_when_all_printings_excluded(): void
    {
        MtgSet::create([
            'scryfall_id' => '11111111-1111-1111-1111-111111111111',
            'code'        => 'art',
            'name'        => 'Art Series',
            'set_type'    => 'art_series',
            'search_uri'  => '',
        ]);
        MtgSet::create([
            'scryfall_id' => '22222222-2222-2222-2222-222222222222',
            'code'        => 'exp',
            'name'        => 'Expansion',
            'set_type'    => 'expansion',
            'search_uri'  => '',
        ]);

        $excluded = '00000000-0000-0000-0000-000000000050';
        $mixed    = '00000000-0000-0000-0000-000000000051';
        ScryfallCard::factory()->create([
            'oracle_id'   => $excluded,
            'name'        => 'Art-Only',
            'set_code'    => 'art',
            'is_playtest' => false,
        ]);
        // The mixed oracle has both an art-series and an expansion printing.
        ScryfallCard::factory()->create([
            'oracle_id' => $mixed, 'name' => 'Mixed',
            'set_code'  => 'art',
        ]);
        ScryfallCard::factory()->create([
            'oracle_id' => $mixed, 'name' => 'Mixed',
            'set_code'  => 'exp',
        ]);

        $this->rebuild();

        $this->assertTrue((bool) ScryfallOracle::find($excluded)->excluded_from_catalog);
        $this->assertFalse((bool) ScryfallOracle::find($mixed)->excluded_from_catalog);
    }

    public function test_max_released_at_aggregates_across_printings(): void
    {
        $oid = '00000000-0000-0000-0000-000000000060';
        ScryfallCard::factory()->create([
            'oracle_id'   => $oid, 'name' => 'rel',
            'released_at' => '2015-01-01',
        ]);
        ScryfallCard::factory()->create([
            'oracle_id'   => $oid, 'name' => 'rel',
            'released_at' => '2024-06-15',
        ]);

        $this->rebuild();

        $this->assertSame(
            '2024-06-15',
            ScryfallOracle::find($oid)->max_released_at->format('Y-m-d'),
        );
    }

    public function test_non_numeric_collector_numbers_dont_break_cast(): void
    {
        // Scryfall has collector numbers like '14p', '★123', 'prerelease'
        // that CAST(... AS UNSIGNED) trips on under strict mode. The
        // representative picker uses REGEXP_SUBSTR to extract digits so
        // the INSERT doesn't fail. Exercise each shape against one oracle.
        $oid = '00000000-0000-0000-0000-000000000080';
        foreach (['14p', '★123', 'prerelease', '50'] as $i => $cn) {
            ScryfallCard::factory()->create([
                'oracle_id'        => $oid,
                'name'             => 'Oddly Numbered',
                'set_code'         => 'odd' . $i,
                'collector_number' => $cn,
                'released_at'      => '2020-01-0' . ($i + 1),
            ]);
        }

        $this->rebuild();

        $this->assertSame(1, ScryfallOracle::where('oracle_id', $oid)->count());
        $this->assertSame(4, ScryfallOracle::find($oid)->printing_count);
    }

    public function test_layout_flags_derived_from_layout_column(): void
    {
        $flip     = '00000000-0000-0000-0000-000000000070';
        $mdfc     = '00000000-0000-0000-0000-000000000071';
        $leveler  = '00000000-0000-0000-0000-000000000072';

        ScryfallCard::factory()->create(['oracle_id' => $flip,    'name' => 'f', 'layout' => 'flip']);
        ScryfallCard::factory()->create(['oracle_id' => $mdfc,    'name' => 'm', 'layout' => 'modal_dfc']);
        ScryfallCard::factory()->create(['oracle_id' => $leveler, 'name' => 'l', 'layout' => 'leveler']);

        $this->rebuild();

        $this->assertTrue((bool) ScryfallOracle::find($flip)->is_flip);
        $this->assertFalse((bool) ScryfallOracle::find($flip)->is_mdfc);
        $this->assertTrue((bool) ScryfallOracle::find($mdfc)->is_mdfc);
        $this->assertTrue((bool) ScryfallOracle::find($leveler)->is_leveler);
    }
}
