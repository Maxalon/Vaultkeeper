<?php

namespace App\Services;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;

class ManaBoxImportService
{
    /**
     * Import a ManaBox CSV export. One collection_entries row per CSV line —
     * never aggregated. Rows are validated against scryfall_cards so we
     * don't accept references to cards missing from the canonical reference
     * data (which would break image/name/type lookups downstream).
     *
     * @return array{imported:int,skipped:int,warnings:string[]}
     */
    public function import(UploadedFile $file, User $user, ?int $locationId): array
    {
        // First import after a fresh deploy: lazily populate the set symbol
        // catalog. The assets disk is env-driven (local in dev, s3 in prod),
        // so this check works against either backing store.
        if (empty(Storage::disk('assets')->allFiles('sets'))) {
            Log::info('Sets directory empty, running sets:sync automatically.');
            Artisan::call('sets:sync');
        }

        $reader = Reader::createFromPath($file->getRealPath(), 'r');
        $reader->setHeaderOffset(0);

        // Materialize so we can count and so each row gets a stable line number
        // for warnings (line 1 is the header, so data rows start at 2).
        $rows = iterator_to_array($reader->getRecords(), false);

        if (count($rows) === 0) {
            throw ValidationException::withMessages([
                'csv_file' => ['CSV file is empty'],
            ]);
        }

        $imported = 0;
        $skipped  = 0;
        $warnings = [];

        DB::transaction(function () use (
            $rows,
            $user,
            $locationId,
            &$imported,
            &$skipped,
            &$warnings,
        ) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2; // +1 for header, +1 to make 1-indexed

                if ($this->isEmptyRow($row)) {
                    $skipped++;
                    continue;
                }

                $scryfallId = trim((string) ($row['Scryfall ID'] ?? ''));
                if ($scryfallId === '') {
                    $warnings[] = "Row {$rowNumber}: missing Scryfall ID, skipped";
                    $skipped++;
                    continue;
                }

                if (! ScryfallCard::where('scryfall_id', $scryfallId)->exists()) {
                    $warnings[] = "Row {$rowNumber}: scryfall_id {$scryfallId} not found in scryfall_cards — run scryfall:sync-bulk and retry";
                    $skipped++;
                    continue;
                }

                CollectionEntry::create([
                    'user_id'     => $user->id,
                    'scryfall_id' => $scryfallId,
                    'location_id' => $locationId,
                    'quantity'    => max(1, (int) ($row['Quantity'] ?? 1)),
                    'condition'   => $this->mapCondition((string) ($row['Condition'] ?? ''), $warnings, $rowNumber),
                    'foil'        => strtolower(trim((string) ($row['Foil'] ?? ''))) === 'foil',
                    'notes'       => $this->buildNotes($row['Language'] ?? null),
                ]);

                $imported++;
            }
        });

        if ($locationId && $imported > 0) {
            Location::find($locationId)?->refreshSetCodes();
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'warnings' => $warnings,
        ];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param  string[]  $warnings
     */
    private function mapCondition(string $value, array &$warnings, int $rowNumber): string
    {
        return match (strtolower(trim($value))) {
            'near_mint'         => 'NM',
            'lightly_played'    => 'LP',
            'moderately_played' => 'MP',
            'heavily_played'    => 'HP',
            'damaged'           => 'DMG',
            default             => $this->defaultCondition($value, $rowNumber, $warnings),
        };
    }

    /**
     * @param  string[]  $warnings
     */
    private function defaultCondition(string $value, int $rowNumber, array &$warnings): string
    {
        $warnings[] = "Row {$rowNumber}: unknown condition \"{$value}\", defaulted to NM";
        return 'NM';
    }

    private function buildNotes(?string $language): ?string
    {
        $lang = strtolower(trim((string) $language));
        if ($lang === '' || $lang === 'en') {
            return null;
        }
        return "Language: {$language}";
    }
}
