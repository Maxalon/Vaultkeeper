<?php

namespace Tests\Feature;

use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsvDeckImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    private const ATRAXA_ID = '11111111-1111-1111-1111-111111111111';
    private const SOL_RING_ID = '22222222-2222-2222-2222-222222222222';
    private const BOLT_ID = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        ScryfallCard::factory()->create([
            'scryfall_id'    => self::ATRAXA_ID,
            'name'           => 'Atraxa, Praetors\' Voice',
            'set_code'       => 'c16',
            'type_line'      => 'Legendary Creature — Phyrexian Angel Horror',
            'color_identity' => ['W', 'U', 'B', 'G'],
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id' => self::SOL_RING_ID,
            'name'        => 'Sol Ring',
            'set_code'    => 'cmr',
            'type_line'   => 'Artifact',
        ]);
        ScryfallCard::factory()->create([
            'scryfall_id' => self::BOLT_ID,
            'name'        => 'Lightning Bolt',
            'set_code'    => 'leb',
            'type_line'   => 'Instant',
        ]);

        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);

        Http::preventStrayRequests();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function csvFile(string $content, string $filename = 'deck.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    public function test_csv_import_with_zone_column_splits_main_side_maybe(): void
    {
        // Atraxa's display name contains a comma — keep it quoted so the
        // CSV reader doesn't shift columns. ManaBox itself emits the same
        // form for any name with punctuation.
        $csv = "Name,Set code,Collector number,Quantity,Foil,Condition,Language,Scryfall ID,Zone\n"
            ."\"Atraxa, Praetors' Voice\",c16,28,1,normal,near_mint,en,".self::ATRAXA_ID.",main\n"
            ."Sol Ring,cmr,255,1,normal,near_mint,en,".self::SOL_RING_ID.",main\n"
            ."Lightning Bolt,leb,161,3,normal,near_mint,en,".self::BOLT_ID.",main\n"
            ."Lightning Bolt,leb,161,2,normal,near_mint,en,".self::BOLT_ID.",side\n"
            ."Lightning Bolt,leb,161,1,normal,near_mint,en,".self::BOLT_ID.",maybe\n";

        $response = $this->withHeaders($this->headers())
            ->post('/api/decks/import/csv', [
                'csv_file' => $this->csvFile($csv),
                'name'     => 'Atraxa CSV',
                'format'   => 'commander',
            ])
            ->assertCreated();

        // CSV has no commander column, so Atraxa lands in mainboard as a
        // regular entry: 1 Atraxa + 1 Sol Ring + 3+2+1 Bolts = 8.
        $this->assertSame(8, $response->json('imported'));
        $this->assertSame(0, $response->json('skipped'));
        $this->assertSame([], $response->json('warnings'));

        $deckId = $response->json('deck.id');

        $this->assertDatabaseHas('decks', [
            'id'     => $deckId,
            'name'   => 'Atraxa CSV',
            'format' => 'commander',
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => self::ATRAXA_ID,
            'zone' => 'main', 'quantity' => 1, 'is_commander' => false,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => self::SOL_RING_ID,
            'zone' => 'main', 'quantity' => 1,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => self::BOLT_ID,
            'zone' => 'main', 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => self::BOLT_ID,
            'zone' => 'side', 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('deck_entries', [
            'deck_id' => $deckId, 'scryfall_id' => self::BOLT_ID,
            'zone' => 'maybe', 'quantity' => 1,
        ]);
    }

    public function test_csv_import_defaults_zone_to_main_when_column_missing(): void
    {
        // No Zone column — every row should land in mainboard.
        $csv = "Name,Quantity,Scryfall ID\n"
            ."Sol Ring,1,".self::SOL_RING_ID."\n"
            ."Lightning Bolt,4,".self::BOLT_ID."\n";

        $response = $this->withHeaders($this->headers())
            ->post('/api/decks/import/csv', [
                'csv_file' => $this->csvFile($csv),
                'name'     => 'Just Mainboard',
                'format'   => 'modern',
            ])
            ->assertCreated();

        $this->assertSame(5, $response->json('imported'));
        $this->assertSame(0, $response->json('skipped'));

        $deckId = $response->json('deck.id');
        $zones = DeckEntry::where('deck_id', $deckId)->pluck('zone')->all();
        $this->assertNotEmpty($zones);
        foreach ($zones as $zone) {
            $this->assertSame('main', $zone);
        }
    }

    public function test_csv_import_warns_on_unknown_scryfall_id(): void
    {
        // Empty Scryfall fallback — the unknown id should surface as a
        // warning while the rest of the deck still imports.
        Http::fake([
            'api.scryfall.com/cards/collection' => Http::response(['data' => []], 200),
        ]);

        $csv = "Name,Quantity,Scryfall ID,Zone\n"
            ."Sol Ring,1,".self::SOL_RING_ID.",main\n"
            ."Made Up Card,2,deadbeef-dead-dead-dead-deadbeefdead,main\n";

        $response = $this->withHeaders($this->headers())
            ->post('/api/decks/import/csv', [
                'csv_file' => $this->csvFile($csv),
                'name'     => 'Has Typo',
                'format'   => 'commander',
            ])
            ->assertCreated();

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame(2, $response->json('skipped'));
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Made Up Card', implode("\n", $warnings));
    }

    public function test_csv_import_requires_name_and_format(): void
    {
        $csv = "Name,Quantity,Scryfall ID\nSol Ring,1,".self::SOL_RING_ID."\n";

        $this->withHeaders($this->headers())
            ->post('/api/decks/import/csv', [
                'csv_file' => $this->csvFile($csv),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'format']);
    }

    public function test_csv_import_rejects_unknown_format(): void
    {
        $csv = "Name,Quantity,Scryfall ID\nSol Ring,1,".self::SOL_RING_ID."\n";

        $this->withHeaders($this->headers())
            ->post('/api/decks/import/csv', [
                'csv_file' => $this->csvFile($csv),
                'name'     => 'Banana',
                'format'   => 'banana',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }
}
