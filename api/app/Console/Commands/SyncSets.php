<?php

namespace App\Console\Commands;

use App\Models\MtgSet;
use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncSets extends Command
{
    protected $signature = 'sets:sync';

    protected $description = 'Download all MTG set symbols from the Hexproof catalog and the card back image.';

    /** Rarity letters we care about. Everything else (WM, T, 80, …) is ignored. */
    private const RARITIES = ['C', 'U', 'R', 'M'];

    /** Logical disk these assets live on. Resolved at runtime to local or s3. */
    private const DISK = 'assets';

    public function handle(ScryfallService $scryfall): int
    {
        $disk = Storage::disk(self::DISK);

        // 1. Card back (shared asset, only fetched once).
        if ($disk->exists('card-back.jpg')) {
            $this->info('Card back already exists, skipping.');
        } else {
            if ($scryfall->downloadToDisk('http://cards.scryfall.io/back.png', self::DISK, 'card-back.jpg')) {
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
            function (string $code) use ($catalog, $disk, $scryfall, &$downloaded, &$skipped, &$failed) {
                $rarities = (array) ($catalog[$code] ?? []);

                foreach (self::RARITIES as $rarity) {
                    if (! isset($rarities[$rarity]) || ! is_string($rarities[$rarity])) {
                        continue;
                    }

                    $dest = "sets/{$code}/{$rarity}.svg";
                    if ($disk->exists($dest)) {
                        $skipped++;
                        continue;
                    }

                    $url = $rarities[$rarity];

                    if ($scryfall->downloadToDisk($url, self::DISK, $dest)) {
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

        $this->backfillFromScryfall($scryfall);
        $this->syncSymbols($scryfall);

        return self::SUCCESS;
    }

    /**
     * Fill per-rarity gaps using Scryfall's icon_svg_uri (already populated
     * in the sets table by BulkSyncService::syncSets). Hexproof's catalog
     * doesn't cover every set — SLD, H2R, and various promo printings are
     * missing — so for any rarity slot still empty after the Hexproof loop,
     * we download Scryfall's single monochrome icon once and fan it out to
     * C/U/R/M. Rarity colour-coding is lost for these sets, but a usable
     * symbol beats the SetSymbol.vue fallback "?".
     */
    private function backfillFromScryfall(ScryfallService $scryfall): void
    {
        $disk = Storage::disk(self::DISK);
        $sets = MtgSet::query()
            ->whereNotNull('icon_svg_uri')
            ->get(['code', 'icon_svg_uri']);

        // List every existing key under sets/ once (one ListObjectsV2 round
        // trip against S3/MinIO) and hash it for O(1) lookups. A naive
        // $disk->exists() per rarity would issue 1031*4 ≈ 4000 HeadObject
        // calls — fast on local disk, minutes over the network.
        $existing = array_flip($disk->allFiles('sets'));

        $this->info("Checking {$sets->count()} sets for missing rarity symbols.");

        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        $this->withProgressBar(
            $sets->all(),
            function (MtgSet $set) use ($existing, $disk, $scryfall, &$downloaded, &$skipped, &$failed) {
                $code    = strtoupper($set->code);
                $missing = [];
                foreach (self::RARITIES as $rarity) {
                    if (! isset($existing["sets/{$code}/{$rarity}.svg"])) {
                        $missing[] = $rarity;
                    }
                }

                if (empty($missing)) {
                    $skipped++;
                    return;
                }

                // Download Scryfall's icon into the first missing slot, then
                // copy to the remaining ones so we only hit Scryfall once.
                $first     = array_shift($missing);
                $firstDest = "sets/{$code}/{$first}.svg";

                if (! $scryfall->downloadToDisk($set->icon_svg_uri, self::DISK, $firstDest)) {
                    $failed++;
                    Log::warning("sets:sync backfill failed to download {$set->icon_svg_uri}");
                    return;
                }

                foreach ($missing as $rarity) {
                    $disk->copy($firstDest, "sets/{$code}/{$rarity}.svg");
                }

                $downloaded++;
            }
        );

        $this->newLine(2);
        $this->info("Backfill complete. Filled: {$downloaded}, Intact: {$skipped}, Failed: {$failed}");
    }

    /**
     * Download every mana / cost symbol SVG from Scryfall's symbology endpoint
     * into the assets disk under symbols/{SYMBOL}.svg. Existing files are skipped.
     */
    private function syncSymbols(ScryfallService $scryfall): void
    {
        $disk    = Storage::disk(self::DISK);
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

            $dest = "symbols/{$clean}.svg";

            if ($disk->exists($dest)) {
                $skipped++;
                continue;
            }

            if ($scryfall->downloadToDisk($url, self::DISK, $dest)) {
                $downloaded++;
            } else {
                $failed++;
                Log::warning("sets:sync failed to download symbol {$url}");
            }
        }

        $this->info("Symbols sync complete. Downloaded: {$downloaded}, Skipped: {$skipped}, Failed: {$failed}");
    }
}
