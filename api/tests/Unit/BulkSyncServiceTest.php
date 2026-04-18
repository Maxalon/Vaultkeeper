<?php

namespace Tests\Unit;

use App\Services\BulkSyncService;
use App\Services\ScryfallService;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * derivePartnerScope is the load-bearing piece of the partner-matching
 * system — a regex regression here silently mis-bucketing Partner variants
 * would leak into every legality check. DataProvider covers every variant
 * we've observed in Scryfall's oracle text plus a couple defensive edges.
 */
class BulkSyncServiceTest extends TestCase
{
    private function service(): BulkSyncService
    {
        // Pure function under test has no dependency on ScryfallService state
        // but the constructor still needs one — instantiate with a fresh Http
        // factory so nothing ever tries to call out.
        return new BulkSyncService(new ScryfallService(new HttpFactory));
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: ?string, 2: ?string}>
     */
    public static function partnerScopeProvider(): array
    {
        return [
            'Tana — plain Partner' => [
                ['Partner'],
                'Partner (You can have two commanders if both have partner.)',
                'plain',
            ],
            'Pir — Partner with X (falls through to plain since Partner-with space separator doesn\'t match the em-dash regex)' => [
                ['Partner with', 'Partner'],
                'Partner with Toothy, Imaginary Friend (When this creature enters, target player may put Toothy into their hand from their library, then shuffle.)',
                'plain',
            ],
            'Bjorna — Friends forever' => [
                ['Partner'],
                "Partner\u{2014}Friends forever (You can have two commanders if both have this ability.)",
                'friends_forever',
            ],
            'Abby — Survivors' => [
                ['Partner'],
                "Partner\u{2014}Survivors (You can have two commanders if both have this ability.)",
                'survivors',
            ],
            'Leonardo — Character select' => [
                ['Partner'],
                "Partner\u{2014}Character select (You can have two commanders if both have this ability.)",
                'character_select',
            ],
            'Non-partner card' => [
                ['Flying'],
                'Flying, vigilance.',
                null,
            ],
            'Partner keyword with null oracle — defensive' => [
                ['Partner'],
                null,
                'plain',
            ],
        ];
    }

    #[DataProvider('partnerScopeProvider')]
    public function test_derive_partner_scope(array $keywords, ?string $oracleText, ?string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->service()->derivePartnerScope($keywords, $oracleText),
        );
    }
}
