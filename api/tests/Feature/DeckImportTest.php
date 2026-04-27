<?php

namespace Tests\Feature;

use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeckImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the cards we'll reference across all three sources — the DB
        // lookup path should resolve them without hitting Scryfall.
        ScryfallCard::factory()->create([
            'scryfall_id'    => '11111111-1111-1111-1111-111111111111',
            'name'           => 'Atraxa, Praetors\' Voice',
            'set_code'       => 'c16',
            'type_line'      => 'Legendary Creature — Phyrexian Angel Horror',
            'color_identity' => ['W', 'U', 'B', 'G'],
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id' => '22222222-2222-2222-2222-222222222222',
            'name'        => 'Sol Ring',
            'set_code'    => 'cmr',
            'type_line'   => 'Artifact',
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id' => '33333333-3333-3333-3333-333333333333',
            'name'        => 'Lightning Bolt',
            'set_code'    => 'leb',
            'type_line'   => 'Instant',
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id' => '44444444-4444-4444-4444-444444444444',
            'name'        => 'Lurrus of the Dream-Den',
            'set_code'    => 'iko',
            'type_line'   => 'Legendary Creature — Cat Nightmare',
            'keywords'    => ['Companion'],
        ]);

        $this->user  = User::factory()->create();
        $this->token = auth('api')->login($this->user);

        // No external HTTP should ever fire for the happy paths (everything
        // resolves locally). Fail loudly if something slips through.
        Http::preventStrayRequests();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_text_import_creates_deck_with_commander_and_zones(): void
    {
        $text = <<<TXT
        Commander
        1 Atraxa, Praetors' Voice

        Deck
        1 Sol Ring
        3 Lightning Bolt

        Sideboard
        2 Lightning Bolt
        TXT;

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'text',
                'text'   => $text,
                'name'   => 'Atraxa Superfriends',
                'format' => 'commander',
            ])
            ->assertCreated();

        // Main: 1 Sol Ring + 3 Lightning Bolt = 4 cards; sideboard: 2 Bolt.
        // The commander is synced into main by syncCommanderEntries but
        // doesn't count toward "imported" (it was not an entry line).
        $this->assertSame(6, $response->json('imported'));
        $this->assertSame(0, $response->json('skipped'));
        $this->assertSame([], $response->json('warnings'));

        $deckId = $response->json('deck.id');
        $this->assertDatabaseHas('decks', [
            'id'                      => $deckId,
            'name'                    => 'Atraxa Superfriends',
            'format'                  => 'commander',
            'commander_1_scryfall_id' => '11111111-1111-1111-1111-111111111111',
            'color_identity'          => 'WUBG',
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'      => $deckId,
            'scryfall_id'  => '11111111-1111-1111-1111-111111111111',
            'is_commander' => true,
            'zone'         => 'main',
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'     => $deckId, 'scryfall_id' => '22222222-2222-2222-2222-222222222222',
            'zone'        => 'main', 'quantity'    => 1,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'     => $deckId, 'scryfall_id' => '33333333-3333-3333-3333-333333333333',
            'zone'        => 'main', 'quantity'    => 3,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'     => $deckId, 'scryfall_id' => '33333333-3333-3333-3333-333333333333',
            'zone'        => 'side', 'quantity'    => 2,
        ]);
    }

    public function test_text_import_records_warning_for_unknown_card(): void
    {
        // Stray-request prevention + local-only resolution means the Scryfall
        // fallback fires a /cards/collection call that we stub to return nothing
        // — the warning path is what we're testing.
        Http::fake([
            'api.scryfall.com/cards/collection' => Http::response(['data' => []], 200),
        ]);

        $text = <<<TXT
        Commander
        1 Atraxa, Praetors' Voice

        Deck
        1 Sol Ring
        1 Completely Made Up Card
        TXT;

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'text',
                'text'   => $text,
                'name'   => 'With Typo',
                'format' => 'commander',
            ])
            ->assertCreated();

        $this->assertSame(1, $response->json('imported'));       // Sol Ring only
        $this->assertSame(1, $response->json('skipped'));
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Completely Made Up Card', implode("\n", $warnings));
    }

    public function test_archidekt_import_uses_scryfall_uuid_from_source(): void
    {
        // Seed an alternate Bolt printing so a name-based lookup would be
        // ambiguous. Archidekt's `card.uid` should win regardless.
        ScryfallCard::factory()->create([
            'scryfall_id' => '99999999-9999-9999-9999-999999999999',
            'name'        => 'Lightning Bolt',
            'set_code'    => 'm11',
            'type_line'   => 'Instant',
        ]);

        Http::fake([
            'archidekt.com/api/decks/*' => Http::response([
                'id'           => 42,
                'name'         => 'Four-Color Atraxa',
                'deckFormat'   => 'commander',
                'cards'        => [
                    [
                        'quantity'   => 1,
                        'categories' => ['Commander'],
                        'card' => [
                            'uid'        => '11111111-1111-1111-1111-111111111111',
                            'oracleCard' => ['name' => "Atraxa, Praetors' Voice"],
                            'edition'    => ['editioncode' => 'c16'],
                        ],
                    ],
                    [
                        'quantity'   => 1,
                        'categories' => [],
                        'card' => [
                            'uid'        => '22222222-2222-2222-2222-222222222222',
                            'oracleCard' => ['name' => 'Sol Ring'],
                            'edition'    => ['editioncode' => 'cmr'],
                        ],
                    ],
                    [
                        'quantity'   => 2,
                        'categories' => ['Sideboard'],
                        'card' => [
                            'uid'        => '99999999-9999-9999-9999-999999999999',
                            'oracleCard' => ['name' => 'Lightning Bolt'],
                            'edition'    => ['editioncode' => 'm11'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'archidekt',
                'url'    => 'https://archidekt.com/decks/15517081/artifact_lands',
            ])
            ->assertCreated();

        $this->assertSame(3, $response->json('imported'));
        $this->assertSame([], $response->json('warnings'));

        $deckId = $response->json('deck.id');
        $this->assertDatabaseHas('decks', [
            'id'                      => $deckId,
            'name'                    => 'Four-Color Atraxa',
            'commander_1_scryfall_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        // The printing Archidekt pointed at (M11 Bolt), not the LEB one.
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => '99999999-9999-9999-9999-999999999999',
            'zone'    => 'side', 'quantity' => 2,
        ]);
        $this->assertDatabaseMissing('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => '33333333-3333-3333-3333-333333333333',
        ]);
    }

    public function test_archidekt_oathbreaker_import_resolves_format_and_signature_spell(): void
    {
        // Wrenn and Six = legendary planeswalker (legal oathbreaker).
        // Fork in the Road = sorcery (legal signature spell).
        ScryfallCard::factory()->create([
            'scryfall_id'    => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'name'           => 'Wrenn and Six',
            'set_code'       => 'mh1',
            'type_line'      => 'Legendary Planeswalker — Wrenn',
            'color_identity' => ['R', 'G'],
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id'    => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'name'           => 'Fork in the Road',
            'set_code'       => 'mh3',
            'type_line'      => 'Sorcery',
            'color_identity' => ['G'],
        ]);

        // Real Archidekt API response: deckFormat is the integer 14
        // (Oathbreaker), and the user's deck has both the oathbreaker AND the
        // signature spell tagged with the "Commander" category — exactly the
        // shape that used to misroute the spell into commander_2_scryfall_id.
        Http::fake([
            'archidekt.com/api/decks/*' => Http::response([
                'id'         => 99,
                'name'       => 'Wrenn Oathbreaker',
                'deckFormat' => 14,
                'cards'      => [
                    [
                        'quantity'   => 1,
                        'categories' => ['Commander'],
                        'card' => [
                            'uid'        => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                            'oracleCard' => ['name' => 'Wrenn and Six'],
                            'edition'    => ['editioncode' => 'mh1'],
                        ],
                    ],
                    [
                        'quantity'   => 1,
                        'categories' => ['Commander'],
                        'card' => [
                            'uid'        => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                            'oracleCard' => ['name' => 'Fork in the Road'],
                            'edition'    => ['editioncode' => 'mh3'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'archidekt',
                'url'    => 'https://archidekt.com/decks/99/wrenn',
            ])
            ->assertCreated();

        $deckId = $response->json('deck.id');

        // Format should be derived from the numeric deckFormat=14, not
        // silently fall back to "commander".
        $this->assertDatabaseHas('decks', [
            'id'                      => $deckId,
            'format'                  => 'oathbreaker',
            'commander_1_scryfall_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'commander_2_scryfall_id' => null,
        ]);
        // Wrenn lands in the commander slot.
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'            => $deckId,
            'scryfall_id'        => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'is_commander'       => true,
            'is_signature_spell' => false,
            'zone'               => 'main',
        ]);
        // Fork is reclassified as a signature spell, attached to Wrenn.
        $oathbreakerEntryId = \App\Models\DeckEntry::where('deck_id', $deckId)
            ->where('scryfall_id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
            ->value('id');
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'                => $deckId,
            'scryfall_id'            => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'is_commander'           => false,
            'is_signature_spell'     => true,
            'signature_for_entry_id' => $oathbreakerEntryId,
            'zone'                   => 'main',
        ]);
    }

    public function test_archidekt_signature_spell_category_is_recognised(): void
    {
        ScryfallCard::factory()->create([
            'scryfall_id'    => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'name'           => 'Wrenn and Six',
            'set_code'       => 'mh1',
            'type_line'      => 'Legendary Planeswalker — Wrenn',
            'color_identity' => ['R', 'G'],
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id'    => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'name'           => 'Fork in the Road',
            'set_code'       => 'mh3',
            'type_line'      => 'Sorcery',
            'color_identity' => ['G'],
        ]);

        // Properly-categorised Archidekt deck: oathbreaker has "Commander",
        // spell has "Signature Spell". The string-format legacy path also
        // gets exercised here ("oathbreaker" instead of integer 14).
        Http::fake([
            'archidekt.com/api/decks/*' => Http::response([
                'id'         => 100,
                'name'       => 'Wrenn Oathbreaker (clean)',
                'deckFormat' => 'oathbreaker',
                'cards'      => [
                    [
                        'quantity'   => 1,
                        'categories' => ['Commander'],
                        'card' => [
                            'uid'        => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                            'oracleCard' => ['name' => 'Wrenn and Six'],
                            'edition'    => ['editioncode' => 'mh1'],
                        ],
                    ],
                    [
                        'quantity'   => 1,
                        'categories' => ['Signature Spell'],
                        'card' => [
                            'uid'        => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                            'oracleCard' => ['name' => 'Fork in the Road'],
                            'edition'    => ['editioncode' => 'mh3'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'archidekt',
                'url'    => 'https://archidekt.com/decks/100/wrenn',
            ])
            ->assertCreated();

        $deckId = $response->json('deck.id');
        $this->assertDatabaseHas('decks', [
            'id'                      => $deckId,
            'format'                  => 'oathbreaker',
            'commander_1_scryfall_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'            => $deckId,
            'scryfall_id'        => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'is_signature_spell' => true,
        ]);
    }

    public function test_moxfield_import_parses_commander_and_companion(): void
    {
        Http::fake([
            'api2.moxfield.com/v3/decks/all/*' => Http::response([
                'name'   => 'Lurrus Boots',
                'format' => 'modern',
                'boards' => [
                    'commanders' => ['cards' => [
                        'a' => ['quantity' => 1, 'card' => ['scryfall_id' => '11111111-1111-1111-1111-111111111111', 'name' => "Atraxa, Praetors' Voice", 'set' => 'c16']],
                    ]],
                    'companions' => ['cards' => [
                        'b' => ['quantity' => 1, 'card' => ['scryfall_id' => '44444444-4444-4444-4444-444444444444', 'name' => 'Lurrus of the Dream-Den', 'set' => 'iko']],
                    ]],
                    'mainboard' => ['cards' => [
                        'c' => ['quantity' => 1, 'card' => ['scryfall_id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Sol Ring', 'set' => 'cmr']],
                        'd' => ['quantity' => 4, 'card' => ['scryfall_id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Lightning Bolt', 'set' => 'leb']],
                    ]],
                    'sideboard'  => ['cards' => []],
                    'maybeboard' => ['cards' => []],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'moxfield',
                'url'    => 'https://moxfield.com/decks/TWx0hjuCOkaQdTm_q27iog',
            ])
            ->assertCreated();

        $this->assertSame(5, $response->json('imported'));
        $deckId = $response->json('deck.id');
        $this->assertDatabaseHas('decks', [
            'id'                      => $deckId,
            'commander_1_scryfall_id' => '11111111-1111-1111-1111-111111111111',
            'companion_scryfall_id'   => '44444444-4444-4444-4444-444444444444',
            'format'                  => 'modern',
        ]);
    }

    public function test_text_import_respects_collector_number_and_foil_markers(): void
    {
        // Seed two printings of the same card in the same set — only the
        // collector-number match should pick the right one. This is the
        // exact shape of the bug the user hit with Archidekt exports.
        ScryfallCard::factory()->create([
            'scryfall_id'      => '55555555-5555-5555-5555-555555555555',
            'name'             => 'Arid Mesa',
            'set_code'         => 'mh2',
            'collector_number' => '244',
            'type_line'        => 'Land',
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id'      => '66666666-6666-6666-6666-666666666666',
            'name'             => 'Arid Mesa',
            'set_code'         => 'mh2',
            'collector_number' => '436',
            'type_line'        => 'Land',
        ]);

        $text = <<<TXT
        Commander
        1 Atraxa, Praetors' Voice

        Deck
        1x Arid Mesa (mh2) 436 *F*
        1x Sol Ring (cmr) 240
        TXT;

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'text',
                'text'   => $text,
                'name'   => 'Foil Lands',
                'format' => 'commander',
            ])
            ->assertCreated();

        $this->assertSame(2, $response->json('imported'));
        $deckId = $response->json('deck.id');

        // The collector-number variant, not the first-inserted one.
        $this->assertDatabaseHas('deck_entries', [
            'deck_id'     => $deckId,
            'scryfall_id' => '66666666-6666-6666-6666-666666666666',
        ]);
        $this->assertDatabaseMissing('deck_entries', [
            'deck_id'     => $deckId,
            'scryfall_id' => '55555555-5555-5555-5555-555555555555',
        ]);
    }

    public function test_rejects_url_from_unknown_source(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'archidekt',
                'url'    => 'https://example.com/decks/1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_rejects_text_without_format(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/decks/import', [
                'source' => 'text',
                'text'   => "Deck\n1 Sol Ring",
                'name'   => 'No format',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }
}
