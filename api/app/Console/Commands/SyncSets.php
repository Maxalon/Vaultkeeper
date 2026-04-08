<?php

namespace App\Console\Commands;

use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSets extends Command
{
    protected $signature = 'sets:sync';

    protected $description = 'Download all MTG set symbols from mtg-vectors and the card back image.';

    /** Rarity letters we care about. Everything else (WM, T, …) is ignored. */
    private const RARITIES = ['C', 'U', 'R', 'M'];

    public function handle(ScryfallService $scryfall): int
    {
        // 1. Card back (shared asset, only fetched once).
        $cardBackPath = storage_path('app/public/card-back.jpg');
        if (file_exists($cardBackPath)) {
            $this->info('Card back already exists, skipping.');
        } else {
            if ($scryfall->downloadFile('https://backs.scryfall.io/large/back.jpg', $cardBackPath)) {
                $this->info('Downloaded card back.');
            } else {
                $this->error('Failed to download card back.');
            }
        }

        // 2. Manifest — dictionary of set_code => [supported rarity letters].
        $manifest = $scryfall->fetchManifest();
        $symbols  = $manifest['symbols'] ?? [];
        $this->info('Manifest fetched. '.count($symbols).' sets found.');

        // 3. Walk every set + rarity and download whatever is missing.
        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        $setCodes = array_keys($symbols);

        $this->withProgressBar(
            $setCodes,
            function (string $code) use ($symbols, $scryfall, &$downloaded, &$skipped, &$failed) {
                $supported = (array) ($symbols[$code] ?? []);

                foreach (self::RARITIES as $rarity) {
                    if (! in_array($rarity, $supported, true)) {
                        continue;
                    }

                    $dest = storage_path("app/public/sets/{$code}/{$rarity}.svg");
                    if (file_exists($dest)) {
                        $skipped++;
                        continue;
                    }

                    $url = "https://cdn.jsdelivr.net/gh/Investigamer/mtg-vectors@main/svg/optimized/set/{$code}/{$rarity}.svg";

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

        return self::SUCCESS;
    }
}
