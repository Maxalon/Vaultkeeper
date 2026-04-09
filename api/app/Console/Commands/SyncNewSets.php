<?php

namespace App\Console\Commands;

use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNewSets extends Command
{
    protected $signature = 'sets:sync-new';

    protected $description = 'Download set symbols for any sets in the Hexproof catalog not yet on disk.';

    private const RARITIES = ['C', 'U', 'R', 'M'];

    public function handle(ScryfallService $scryfall): int
    {
        $catalog = $scryfall->fetchSetCatalog();
        $this->info('Catalog fetched. '.count($catalog).' sets found.');

        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        foreach ($catalog as $code => $rarities) {
            $rarities = (array) $rarities;

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
                    Log::warning("sets:sync-new failed to download {$url}");
                }
            }
        }

        $this->info("Sets sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");

        $this->syncSymbols($scryfall);

        return self::SUCCESS;
    }

    /**
     * Download any mana / cost symbol SVGs not yet on disk.
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
                Log::warning("sets:sync-new failed to download symbol {$url}");
            }
        }

        $this->info("Symbols sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");
    }
}
