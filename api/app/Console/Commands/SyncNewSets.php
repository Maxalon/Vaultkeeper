<?php

namespace App\Console\Commands;

use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNewSets extends Command
{
    protected $signature = 'sets:sync-new';

    protected $description = 'Download set symbols for any sets in the mtg-vectors manifest not yet on disk.';

    private const RARITIES = ['C', 'U', 'R', 'M'];

    public function handle(ScryfallService $scryfall): int
    {
        $manifest = $scryfall->fetchManifest();
        $symbols  = $manifest['symbols'] ?? [];
        $this->info('Manifest fetched. '.count($symbols).' sets found.');

        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        foreach ($symbols as $code => $supported) {
            $supported = (array) $supported;

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
                    Log::warning("sets:sync-new failed to download {$url}");
                }
            }
        }

        $this->info("Sets sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
