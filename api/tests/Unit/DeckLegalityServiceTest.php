<?php

namespace Tests\Unit;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\DeckLegalityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DeckLegalityService is the domain's highest-surface-area logic — one
 * DataProvider per check family keeps each provider under ~10 rows and the
 * failure diagnostics readable. Tests hit DB via factories rather than
 * constructing Deck models in memory so relationships load naturally.
 */
class DeckLegalityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeckLegalityService $legality;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legality = new DeckLegalityService;
        $this->user = User::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deck size
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: ?int}>
     */
    public static function deckSizeProvider(): array
    {
        // [format, main_count, side_count, expected_main_required_or_null_if_ok]
        return [
            'commander — exact 100'    => ['commander',   100, 0, null],
            'commander — 99 short'     => ['commander',   99,  0, 100],
            'oathbreaker — exact 60'   => ['oathbreaker', 60,  0, null],
            'standard — exact 60'      => ['standard',    60,  0, null],
            'standard — 59 short'      => ['standard',    59,  0, 60],
            'pauper — exact 60'        => ['pauper',      60,  0, null],
            'modern — 61 too many'     => ['modern',      61,  0, 60],
        ];
    }

    #[DataProvider('deckSizeProvider')]
    public function test_deck_size(string $format, int $main, int $side, ?int $expectedRequired): void
    {
        $deck = $this->seedDeck($format, mainCount: $main, sideCount: $side);
        $results = $this->legality->check($deck);

        $sizeIllegality = collect($results)->firstWhere('type', 'deck_size');
        if ($expectedRequired === null) {
            $this->assertNull($sizeIllegality, 'Expected no deck_size illegality, got one.');
        } else {
            $this->assertNotNull($sizeIllegality, 'Expected a deck_size illegality, got none.');
            $this->assertSame($expectedRequired, $sizeIllegality['expected_count']);
        }
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function sideboardSizeProvider(): array
    {
        return [
            'exactly 0'  => [0,  false],
            'exactly 15' => [15, false],
            '14 is bad'  => [14, true],
            '16 is bad'  => [16, true],
        ];
    }

    #[DataProvider('sideboardSizeProvider')]
    public function test_sideboard_size(int $sideCount, bool $expectIllegal): void
    {
        $deck = $this->seedDeck('standard', mainCount: 60, sideCount: $sideCount);
        $results = $this->legality->check($deck);

        $sideIllegality = collect($results)->firstWhere('type', 'too_many_cards');
        $this->assertSame($expectIllegal, $sideIllegality !== null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Format legality per card
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function formatLegalityProvider(): array
    {
        // [format, card_legality_status, expected_illegality_type_or_null]
        return [
            'legal in format'       => ['standard', 'legal',    null],
            'not_legal in format'   => ['standard', 'not_legal', 'not_legal_in_format'],
            'banned in format'      => ['commander','banned',    'banned_card'],
            'restricted in format'  => ['modern',   'restricted','not_legal_in_format'],
        ];
    }

    #[DataProvider('formatLegalityProvider')]
    public function test_format_legality(string $format, string $status, ?string $expectedType): void
    {
        $card = ScryfallCard::factory()->create([
            'legalities' => [$format => $status] + self::defaultLegalities(),
            'type_line'  => 'Creature — Elf',
            'colors'     => [],
            'color_identity' => [],
        ]);
        $deck = $this->seedDeck($format, mainCount: $format === 'commander' ? 100 : 60, sideCount: 0);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
        ]);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $match = collect($results)->firstWhere('scryfall_id_1', $card->scryfall_id);

        if ($expectedType === null) {
            $this->assertNull($match);
        } else {
            $this->assertNotNull($match);
            $this->assertSame($expectedType, $match['type']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Duplicates (commander format: 1; 60-card formats: 4; exceptions)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: int, 2: array<string, mixed>, 3: bool}>
     */
    public static function duplicateProvider(): array
    {
        return [
            // [format, copies, card_overrides, expectIllegal]
            'commander: 2 copies of a normal card' => ['commander', 2, ['type_line' => 'Creature — Elf'], true],
            'commander: 1 copy of a normal card'   => ['commander', 1, ['type_line' => 'Creature — Elf'], false],
            'standard: 4 copies of a normal card'  => ['standard',  4, ['type_line' => 'Creature — Elf'], false],
            'standard: 5 copies of a normal card'  => ['standard',  5, ['type_line' => 'Creature — Elf'], true],
            'commander: 30 copies of basic land'   => ['commander', 30, ['type_line' => 'Basic Land — Forest'], false],
            'commander: 9 copies of Nazgûl'        => ['commander', 9,  ['name' => 'Nazgûl', 'type_line' => 'Creature — Wraith'], false],
            'commander: 10 copies of Nazgûl'       => ['commander', 10, ['name' => 'Nazgûl', 'type_line' => 'Creature — Wraith'], true],
            'commander: 7 copies of Seven Dwarves' => ['commander', 7,  ['name' => 'Seven Dwarves', 'type_line' => 'Creature — Dwarf'], false],
            'commander: 20 copies of Rat Colony'   => ['commander', 20, [
                'name' => 'Rat Colony', 'type_line' => 'Creature — Rat',
                'oracle_text' => 'A deck can have any number of cards named Rat Colony.',
            ], false],
        ];
    }

    #[DataProvider('duplicateProvider')]
    public function test_duplicates(string $format, int $copies, array $overrides, bool $expectIllegal): void
    {
        $card = ScryfallCard::factory()->create($overrides + [
            'colors'         => [],
            'color_identity' => [],
        ]);

        $deck = $this->seedDeck($format, mainCount: $format === 'commander' ? 100 : 60, sideCount: 0);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => $copies, 'zone' => 'main',
        ]);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $match = collect($results)->firstWhere('oracle_id', $card->oracle_id);

        $this->assertSame($expectIllegal, $match !== null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Color identity (commander format)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<int,string>, 1: array<int,string>, 2: bool, 3: bool}>
     */
    public static function colorIdentityProvider(): array
    {
        return [
            // [commander_identity, card_identity, card_is_land, expectIllegal]
            'mono-G commander, mono-G card'        => [['G'],       ['G'],       false, false],
            'mono-G commander, mono-B card'        => [['G'],       ['B'],       false, true],
            'Naya commander, Jund card'            => [['W','R','G'], ['B','R','G'], false, true],
            '5c commander, any card'               => [['W','U','B','R','G'], ['U','B'], false, false],
            'mono-W commander, B land exempt'      => [['W'],       ['B'],       true,  false],
            'colorless commander, G card illegal'  => [[],          ['G'],       false, true],
        ];
    }

    #[DataProvider('colorIdentityProvider')]
    public function test_color_identity(array $commanderIdentity, array $cardIdentity, bool $cardIsLand, bool $expectIllegal): void
    {
        $commander = ScryfallCard::factory()->create([
            'type_line'      => 'Legendary Creature — Human',
            'color_identity' => $commanderIdentity,
            'colors'         => $commanderIdentity,
        ]);
        $card = ScryfallCard::factory()->create([
            'type_line'      => $cardIsLand ? 'Land' : 'Creature — Elf',
            'color_identity' => $cardIdentity,
            'colors'         => $cardIdentity,
        ]);

        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'Test', 'format' => 'commander',
            'commander_1_scryfall_id' => $commander->scryfall_id,
        ]);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $commander->scryfall_id,
            'quantity' => 1, 'zone' => 'main', 'is_commander' => true,
        ]);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $card->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
        ]);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $match = collect($results)->first(fn ($r) =>
            $r['type'] === 'color_identity_violation' && $r['scryfall_id_1'] === $card->scryfall_id);

        $this->assertSame($expectIllegal, $match !== null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Commander validity
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    public static function commanderValidityProvider(): array
    {
        return [
            'Legendary Creature — valid' => ['Legendary Creature — Human Wizard', null, false],
            'Non-legendary Creature'     => ['Creature — Human Wizard', null, true],
            'Planeswalker can be commander' => ['Legendary Planeswalker — Jace', 'Jace, Vryn\'s Prodigy can be your commander.', false],
            'Vanilla artifact'           => ['Artifact', null, true],
        ];
    }

    #[DataProvider('commanderValidityProvider')]
    public function test_commander_validity(string $typeLine, ?string $oracleText, bool $expectIllegal): void
    {
        $commander = ScryfallCard::factory()->create([
            'type_line'      => $typeLine,
            'oracle_text'    => $oracleText,
            'color_identity' => [],
            'colors'         => [],
        ]);
        $deck = $this->seedDeck('commander', mainCount: 100, sideCount: 0);
        $deck->update(['commander_1_scryfall_id' => $commander->scryfall_id]);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $match = collect($results)->first(fn ($r) =>
            $r['type'] === 'invalid_commander' && $r['scryfall_id_1'] === $commander->scryfall_id);

        $this->assertSame($expectIllegal, $match !== null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Partner pairing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
     */
    public static function partnerPairProvider(): array
    {
        return [
            'Both plain Partner'         => [
                ['partner_scope' => 'plain', 'keywords' => ['Partner']],
                ['partner_scope' => 'plain', 'keywords' => ['Partner']],
                true,
            ],
            'Friends forever + plain Partner reject' => [
                ['partner_scope' => 'friends_forever', 'keywords' => ['Partner']],
                ['partner_scope' => 'plain',           'keywords' => ['Partner']],
                false,
            ],
            'Both Friends forever'       => [
                ['partner_scope' => 'friends_forever', 'keywords' => ['Partner']],
                ['partner_scope' => 'friends_forever', 'keywords' => ['Partner']],
                true,
            ],
            'Doctor + companion'         => [
                ['partner_scope' => null, 'keywords' => ["Doctor's companion"], 'type_line' => 'Legendary Creature — Human Rebel'],
                ['partner_scope' => null, 'keywords' => [], 'type_line' => 'Legendary Creature — Time Lord Doctor'],
                true,
            ],
            'Background + choose a background' => [
                ['partner_scope' => null, 'keywords' => ['Choose a background'], 'type_line' => 'Legendary Creature — Human'],
                ['partner_scope' => null, 'keywords' => [], 'type_line' => 'Legendary Enchantment — Background'],
                true,
            ],
            'Random non-partners reject' => [
                ['partner_scope' => null, 'keywords' => []],
                ['partner_scope' => null, 'keywords' => []],
                false,
            ],
        ];
    }

    #[DataProvider('partnerPairProvider')]
    public function test_partner_pair(array $c1Attrs, array $c2Attrs, bool $expectedPair): void
    {
        $c1 = ScryfallCard::factory()->create(array_merge([
            'type_line' => 'Legendary Creature — Human',
            'color_identity' => [], 'colors' => [],
        ], $c1Attrs));
        $c2 = ScryfallCard::factory()->create(array_merge([
            'type_line' => 'Legendary Creature — Human',
            'color_identity' => [], 'colors' => [],
        ], $c2Attrs));

        $this->assertSame($expectedPair, $this->legality->arePartners($c1, $c2));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oathbreaker — orphan / missing signature spells
    // ─────────────────────────────────────────────────────────────────────

    public function test_oathbreaker_missing_signature_spell(): void
    {
        $ob = ScryfallCard::factory()->create([
            'type_line'      => 'Legendary Planeswalker — Jace',
            'color_identity' => ['U'], 'colors' => ['U'],
        ]);
        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'OB', 'format' => 'oathbreaker',
            'commander_1_scryfall_id' => $ob->scryfall_id,
        ]);
        $obEntry = DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $ob->scryfall_id,
            'quantity' => 1, 'zone' => 'main', 'is_commander' => true,
        ]);
        $this->padMain($deck, 59);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $this->assertTrue(collect($results)->contains('type', 'missing_signature_spell'));
    }

    public function test_oathbreaker_orphan_signature_spell(): void
    {
        $spell = ScryfallCard::factory()->create([
            'type_line' => 'Sorcery', 'color_identity' => [], 'colors' => [],
        ]);
        $deck = Deck::create([
            'user_id' => $this->user->id, 'name' => 'OB', 'format' => 'oathbreaker',
        ]);
        DeckEntry::create([
            'deck_id' => $deck->id, 'scryfall_id' => $spell->scryfall_id,
            'quantity' => 1, 'zone' => 'main',
            'is_signature_spell' => true, 'signature_for_entry_id' => null,
        ]);
        $this->padMain($deck, 59);
        $deck->refresh();

        $results = $this->legality->check($deck);
        $this->assertTrue(collect($results)->contains('type', 'orphan_signature_spell'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function seedDeck(string $format, int $mainCount, int $sideCount): Deck
    {
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => "Test {$format}",
            'format'  => $format,
        ]);
        if ($mainCount > 0) {
            $this->padMain($deck, $mainCount);
        }
        if ($sideCount > 0) {
            $this->padZone($deck, 'side', $sideCount);
        }
        return $deck->fresh();
    }

    /** Fill the main zone with a single-card entry of quantity = $count. */
    private function padMain(Deck $deck, int $count): void
    {
        $this->padZone($deck, 'main', $count);
    }

    private function padZone(Deck $deck, string $zone, int $count): void
    {
        $filler = ScryfallCard::factory()->create([
            'type_line'  => 'Basic Land — Forest',
            'legalities' => self::defaultLegalities(),
            'colors'     => [],
            'color_identity' => [],
        ]);
        DeckEntry::create([
            'deck_id'     => $deck->id,
            'scryfall_id' => $filler->scryfall_id,
            'quantity'    => $count,
            'zone'        => $zone,
        ]);
    }

    /** @return array<string, string> */
    private static function defaultLegalities(): array
    {
        return [
            'commander'   => 'legal',
            'oathbreaker' => 'legal',
            'pauper'      => 'legal',
            'standard'    => 'legal',
            'modern'      => 'legal',
        ];
    }
}
