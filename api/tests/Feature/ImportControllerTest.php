<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    /** Scryfall IDs used across the happy-path CSVs. Seeded into scryfall_cards
     *  in setUp so the import's FK check passes. */
    private const BOLT_ID   = '77c6fa74-5543-42ac-9ead-0e890b188e99';
    private const COUNTER_ID = '0d1498c7-2cc6-4a1f-ab96-97f6a4c18a3e';

    protected function setUp(): void
    {
        parent::setUp();

        // ManaBoxImportService runs `sets:sync` if the assets disk has no
        // files under sets/ — that hits the network. Fake the disk and drop
        // a sentinel so the check short-circuits.
        Storage::fake('assets');
        Storage::disk('assets')->put('sets/.test-keep', '');

        // The import now rejects any scryfall_id not present in scryfall_cards.
        // Seed the canonical rows so the CSV rows are accepted.
        ScryfallCard::factory()->create(['scryfall_id' => self::BOLT_ID,    'name' => 'Lightning Bolt']);
        ScryfallCard::factory()->create(['scryfall_id' => self::COUNTER_ID, 'name' => 'Counterspell']);

        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function csvFile(string $content, string $filename = 'manabox.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    private function validCsv(): string
    {
        return <<<CSV
        Name,Set code,Collector number,Rarity,Quantity,Foil,Condition,Language,Scryfall ID
        Lightning Bolt,LEB,161,common,2,normal,near_mint,en,77c6fa74-5543-42ac-9ead-0e890b188e99
        Counterspell,LEB,55,uncommon,1,foil,lightly_played,en,0d1498c7-2cc6-4a1f-ab96-97f6a4c18a3e
        CSV;
    }

    public function test_import_happy_path_creates_entries(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/import', [
                'csv_file' => $this->csvFile($this->validCsv()),
            ])
            ->assertOk()
            ->assertJson([
                'imported' => 2,
                'skipped'  => 0,
            ]);

        $this->assertSame([], $response->json('warnings'));

        $this->assertDatabaseCount('collection_entries', 2);

        $this->assertDatabaseHas('collection_entries', [
            'user_id'     => $this->user->id,
            'scryfall_id' => self::BOLT_ID,
            'quantity'    => 2,
            'condition'   => 'NM',
            'foil'        => false,
        ]);

        $this->assertDatabaseHas('collection_entries', [
            'user_id'     => $this->user->id,
            'scryfall_id' => self::COUNTER_ID,
            'quantity'    => 1,
            'condition'   => 'LP',
            'foil'        => true,
        ]);
    }

    public function test_import_skips_row_for_unknown_scryfall_id(): void
    {
        $unknown = '11111111-1111-1111-1111-111111111111';
        $csv = <<<CSV
        Name,Set code,Collector number,Rarity,Quantity,Foil,Condition,Language,Scryfall ID
        Lightning Bolt,LEB,161,common,1,normal,near_mint,en,77c6fa74-5543-42ac-9ead-0e890b188e99
        Unknown,LEB,999,common,1,normal,near_mint,en,{$unknown}
        CSV;

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/import', ['csv_file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJson([
                'imported' => 1,
                'skipped'  => 1,
            ]);

        $this->assertStringContainsString('not found in scryfall_cards', $response->json('warnings.0'));
        $this->assertDatabaseMissing('collection_entries', ['scryfall_id' => $unknown]);
    }

    public function test_import_assigns_entries_to_location(): void
    {
        $location = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->authHeaders())
            ->post('/api/import', [
                'csv_file'    => $this->csvFile($this->validCsv()),
                'location_id' => $location->id,
            ])
            ->assertOk();

        $this->assertDatabaseCount('collection_entries', 2);
        $this->assertSame(
            2,
            CollectionEntry::where('location_id', $location->id)->count(),
        );
    }

    public function test_import_rejects_other_users_location(): void
    {
        $otherLoc = Location::factory()->create(); // different user

        $this->withHeaders($this->authHeaders())
            ->post('/api/import', [
                'csv_file'    => $this->csvFile($this->validCsv()),
                'location_id' => $otherLoc->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');

        $this->assertDatabaseCount('collection_entries', 0);
    }

    public function test_import_warns_on_unknown_condition(): void
    {
        $csv = <<<CSV
        Name,Set code,Collector number,Rarity,Quantity,Foil,Condition,Language,Scryfall ID
        Lightning Bolt,LEB,161,common,1,normal,scuffed,en,77c6fa74-5543-42ac-9ead-0e890b188e99
        CSV;

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/import', ['csv_file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJson(['imported' => 1]);

        $this->assertNotEmpty($response->json('warnings'));
        $this->assertStringContainsString('unknown condition', $response->json('warnings.0'));

        // Unknown condition defaults to NM
        $this->assertDatabaseHas('collection_entries', [
            'scryfall_id' => self::BOLT_ID,
            'condition'   => 'NM',
        ]);
    }

    public function test_import_skips_rows_missing_scryfall_id(): void
    {
        $csv = <<<CSV
        Name,Set code,Collector number,Rarity,Quantity,Foil,Condition,Language,Scryfall ID
        Lightning Bolt,LEB,161,common,1,normal,near_mint,en,77c6fa74-5543-42ac-9ead-0e890b188e99
        Broken Row,LEB,999,common,1,normal,near_mint,en,
        CSV;

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/import', ['csv_file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJson([
                'imported' => 1,
                'skipped'  => 1,
            ]);

        $this->assertStringContainsString('missing Scryfall ID', $response->json('warnings.0'));
    }

    public function test_import_rejects_missing_file(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/import', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');
    }

    public function test_import_rejects_non_csv_extension(): void
    {
        $notCsv = UploadedFile::fake()->create('not-a-csv.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders())
            ->post('/api/import', ['csv_file' => $notCsv])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');
    }

    public function test_import_rejects_empty_csv(): void
    {
        $csv = "Name,Set code,Collector number,Rarity,Quantity,Foil,Condition,Language,Scryfall ID\n";

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/import', [
                'csv_file' => $this->csvFile($csv),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');
    }

    public function test_import_requires_authentication(): void
    {
        auth('api')->logout();

        $this->post('/api/import', [
            'csv_file' => $this->csvFile($this->validCsv()),
        ])->assertUnauthorized();
    }
}
