<?php

namespace App\Console\Commands;

use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSets extends Command
{
    protected $signature = 'sets:sync';

    protected $description = 'Download all MTG set symbols from the Hexproof catalog and the card back image.';

    /** Rarity letters we care about. Everything else (WM, T, 80, …) is ignored. */
    private const RARITIES = ['C', 'U', 'R', 'M'];

    public function handle(ScryfallService $scryfall): int
    {
        // 1. Card back (shared asset, only fetched once).
        $cardBackPath = storage_path('app/public/card-back.jpg');
        if (file_exists($cardBackPath)) {
            $this->info('Card back already exists, skipping.');
        } else {
            if ($scryfall->downloadFile('http://cards.scryfall.io/back.png', $cardBackPath)) {
                $this->info('Downloaded card back.');
            } else {
                $this->error('Failed to download card back.');
            }
        }

        // 2. Hexproof catalog — { SET_CODE: { RARITY: svg_url, ... }, ... }.
        $catalog = $scryfall->fetchSetCatalog();
        $this->info('Catalog fetched. '.count($catalog).' sets found.');

        // 3. Walk every set + rarity and download whatever is missing.
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        $setCodes = array_keys($catalog);

        $this->withProgressBar(
            $setCodes,
            function (string $code) use ($catalog, $scryfall, &$downloaded, &$skipped, &$failed) {
                $rarities = (array) ($catalog[$code] ?? []);

                foreach (self::RARITIES as $rarity) {
                    if (! isset($rarities[$rarity]) || ! is_string($rarities[$rarity])) {
                        continue;
                    }

                    $dest = storage_path("app/public/sets/{$code}/{$rarity}.svg");
                    if (file_exists($dest)) {
                        $skipped++;
                        continue;
                    }

                    $url = $rarities[$rarity];

                    if ($scryfall->downloadFile($url, $dest)) {
                        $downloaded++;
                    } else {
                        $failed++;
                        Log::warning("sets:sync failed to download {$url}");
                    }
                }
            }
        );

        $this->newLine(2);
        $this->info("Sets sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");

        $this->syncSymbols($scryfall);

        return self::SUCCESS;
    }

    /**
     * Download every mana / cost symbol SVG from Scryfall's symbology endpoint
     * into storage/app/public/symbols/{SYMBOL}.svg. Existing files are skipped.
     */
    private function syncSymbols(ScryfallService $scryfall): void
    {
        $symbols = $scryfall->fetchSymbology();
        $this->info('Symbology fetched. '.count($symbols).' symbols found.');

        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        foreach ($symbols as $symbol) {
            $raw = (string) ($symbol['symbol'] ?? '');
            $url = (string) ($symbol['svg_uri'] ?? '');
            // Strip the wrapping braces and flatten the slashes used in
            // hybrid / Phyrexian symbols (e.g. "{2/W}" → "2W") so each
            // symbol maps to a single flat filename, not a subdirectory.
            $clean = str_replace('/', '', trim($raw, '{}'));

            if ($clean === '' || $url === '') {
                continue;
            }

            $dest = storage_path("app/public/symbols/{$clean}.svg");

            if (file_exists($dest)) {
                $skipped++;
                continue;
            }

            if ($scryfall->downloadFile($url, $dest)) {
                $downloaded++;
            } else {
                $failed++;
                Log::warning("sets:sync failed to download symbol {$url}");
            }
        }

        $this->info("Symbols sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");
    }
}
