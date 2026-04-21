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

    /**
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>, 4: array<int, string>}>
     */
    public static function typeLineProvider(): array
    {
        return [
            'Creature — Elf Warrior' => [
                'Creature — Elf Warrior',
                [], // multiWord catalog
                [], // supertypes
                ['Creature'],
                ['Elf', 'Warrior'],
            ],
            'Legendary Creature — Elf Druid' => [
                'Legendary Creature — Elf Druid',
                [],
                ['Legendary'],
                ['Creature'],
                ['Elf', 'Druid'],
            ],
            'Instant' => [
                'Instant',
                [],
                [],
                ['Instant'],
                [],
            ],
            'Basic Snow Land — Forest' => [
                'Basic Snow Land — Forest',
                [],
                ['Basic', 'Snow'],
                ['Land'],
                ['Forest'],
            ],
            'Multi-word subtype Time Lord' => [
                'Legendary Creature — Time Lord',
                ['Time Lord'],
                ['Legendary'],
                ['Creature'],
                ['Time Lord'],
            ],
            'Multi-word subtype mixed with plain subtypes' => [
                'Legendary Creature — Time Lord Doctor',
                ['Time Lord'],
                ['Legendary'],
                ['Creature'],
                ['Time Lord', 'Doctor'],
            ],
            'En-dash separator' => [
                "Creature \u{2013} Wizard",
                [],
                [],
                ['Creature'],
                ['Wizard'],
            ],
        ];
    }

    #[DataProvider('typeLineProvider')]
    public function test_parse_type_line(string $input, array $multiWord, array $expectedSuper, array $expectedTypes, array $expectedSub): void
    {
        $out = $this->service()->parseTypeLine($input, $multiWord);
        $this->assertSame($expectedSuper, $out['supertypes'], "supertypes for {$input}");
        $this->assertSame($expectedTypes, $out['types'], "types for {$input}");
        $this->assertSame($expectedSub, $out['subtypes'], "subtypes for {$input}");
    }

    /**
     * Base "plain regular printing" card shape — a normal expansion card
     * with nothing special. Tests override specific keys to exercise each
     * disqualification path.
     *
     * @return array<string, mixed>
     */
    private static function plainCard(): array
    {
        return [
            'nonfoil'       => true,
            'frame_effects' => [],
            'border_color'  => 'black',
            'promo'         => false,
            'variation'     => false,
            'oversized'     => false,
            'set_type'      => 'expansion',
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public static function defaultEligibleProvider(): array
    {
        $base = self::plainCard();
        return [
            'plain regular printing'           => [$base,                                                           true,  'black-border, no frame effects, normal set'],
            'legendary-frame alone is ok'      => [[...$base, 'frame_effects' => ['legendary']],                    true,  'legendary frame is ordinary for legendary cards'],
            'enchantment-frame alone is ok'    => [[...$base, 'frame_effects' => ['enchantment']],                  true,  'Theros-style enchantment frame is intrinsic'],
            'snow frame alone is ok'           => [[...$base, 'frame_effects' => ['snow']],                         true,  'snow frame is intrinsic to snow cards'],
            'legendary + enchantment ok'       => [[...$base, 'frame_effects' => ['legendary', 'enchantment']],     true,  'legendary enchantments (Theros gods etc.)'],
            'showcase frame disqualifies'      => [[...$base, 'frame_effects' => ['showcase']],                     false, 'showcase is an alt treatment'],
            'extendedart disqualifies'         => [[...$base, 'frame_effects' => ['extendedart']],                  false, 'extended art is an alt treatment'],
            'etched disqualifies'              => [[...$base, 'frame_effects' => ['etched']],                       false, 'etched foil is an alt treatment'],
            'nyxtouched disqualifies'          => [[...$base, 'frame_effects' => ['nyxtouched']],                   false, 'Theros Beyond Death holofoil variant'],
            'inverted disqualifies'            => [[...$base, 'frame_effects' => ['enchantment', 'inverted']],      false, 'Aetherdrift inverted variant (Count on Luck #457)'],
            'enchantment + extendedart fails'  => [[...$base, 'frame_effects' => ['enchantment', 'extendedart']],   false, 'still fails because of extendedart'],
            'borderless disqualifies'          => [[...$base, 'border_color' => 'borderless'],                      false, 'borderless is an alt treatment'],
            'gold border disqualifies'         => [[...$base, 'border_color' => 'gold'],                            false, 'World Championship deck reprints'],
            'silver border disqualifies'       => [[...$base, 'border_color' => 'silver'],                          false, 'Un-set joke cards'],
            'yellow border disqualifies'       => [[...$base, 'border_color' => 'yellow'],                          false, 'Alchemy rebalanced / digital-only frame'],
            'white border ok'                  => [[...$base, 'border_color' => 'white'],                           true,  'legitimate on older / retro-frame printings'],
            'foil-only disqualifies'           => [[...$base, 'nonfoil' => false],                                  false, 'foil-only printings are special'],
            'promo flag disqualifies'          => [[...$base, 'promo' => true],                                     false, 'promo stamp on any set'],
            'variation disqualifies'           => [[...$base, 'variation' => true],                                 false, 'variation = alternate printing of same collector #'],
            'oversized disqualifies'           => [[...$base, 'oversized' => true],                                 false, 'oversized Plane / commander cards'],
            'memorabilia set_type excluded'    => [[...$base, 'set_type' => 'memorabilia'],                         false, 'art series cards live here'],
            'token set excluded'               => [[...$base, 'set_type' => 'token'],                               false, 'tokens never default'],
            'secret lair (box) not default'    => [[...$base, 'set_type' => 'box'],                                 false, 'SLD cards can look normal but are premium products'],
            'masterpiece not default'          => [[...$base, 'set_type' => 'masterpiece'],                         false, 'Expeditions / Invocations / etc.'],
            'from_the_vault not default'       => [[...$base, 'set_type' => 'from_the_vault'],                      false, 'premium compilation'],
            'eternal (all-foil) not default'   => [[...$base, 'set_type' => 'eternal'],                             false, 'foil-only premium supplement like Avatar Eternal'],
            'promo set_type not default'       => [[...$base, 'set_type' => 'promo'],                               false, 'caught by promo flag too, belt-and-suspenders'],
            'regular expansion qualifies'      => [[...$base, 'set_type' => 'expansion'],                           true,  'baseline'],
            'core set qualifies'               => [[...$base, 'set_type' => 'core'],                                true,  'core set is a legitimate default'],
            'draft_innovation qualifies'       => [[...$base, 'set_type' => 'draft_innovation'],                    true,  'Conspiracy, Battlebond, Clue Edition, …'],
            'commander set qualifies'          => [[...$base, 'set_type' => 'commander'],                           true,  'commander precons'],
            'masters qualifies'                => [[...$base, 'set_type' => 'masters'],                             true,  'reprint sets'],
            'expansion with missing fields'    => [['set_type' => 'expansion'],                                     false, 'missing nonfoil defaults to false → disqualifies'],
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     */
    #[DataProvider('defaultEligibleProvider')]
    public function test_derive_default_eligible(array $card, bool $expected, string $why): void
    {
        $this->assertSame(
            $expected,
            $this->service()->deriveDefaultEligible($card),
            "case: {$why}",
        );
    }
}
