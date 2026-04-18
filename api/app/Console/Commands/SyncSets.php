<?php

namespace App\Console\Commands;

use App\Models\MtgSet;
use App\Services\MtgVectorsService;
use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncSets extends Command
{
    protected $signature = 'sets:sync';

    protected $description = 'Download set-symbol SVGs from mtg-vectors (with Scryfall icon fallback) and the card back image.';

    /** Rarity letters we actually ship. mtg-vectors also produces 80/T/WM which we ignore. */
    private const RARITIES = ['C', 'U', 'R', 'M'];

    /** Logical disk these assets live on. Resolved at runtime to local or s3. */
    private const DISK = 'assets';

    /**
     * Sidecar written next to Scryfall-fallback rarity files. Its presence
     * means "the C/U/R/M.svg files here came from Scryfall's monochrome
     * icon, not mtg-vectors" — so a later run can safely delete them once
     * mtg-vectors starts shipping proper per-rarity SVGs for the set.
     */
    private const FALLBACK_MARKER = '.scryfall-fallback';

    /**
     * Key storing the mtg-vectors release tag the bucket currently reflects.
     * When the remote tag differs, we re-upload every covered rarity file
     * to pick up upstream fixes.
     */
    private const VERSION_KEY = 'sets/.mtg-vectors.version';

    public function handle(ScryfallService $scryfall, MtgVectorsService $vectors): int
    {
        $disk = Storage::disk(self::DISK);

        $this->syncCardBack($scryfall, $disk);

        $release = $vectors->fetchLatestRelease();
        $this->info("mtg-vectors latest release: {$release['tag']}");

        $storedVersion  = $disk->exists(self::VERSION_KEY) ? trim((string) $disk->get(self::VERSION_KEY)) : '';
        $forceOverwrite = ($storedVersion !== $release['tag']);
        if ($forceOverwrite) {
            $this->info($storedVersion === ''
                ? 'No stored version marker — seeding bucket.'
                : "Stored version {$storedVersion} differs — overwriting all mtg-vectors files.");
        }

        $extractDir = $vectors->downloadAndExtract($release['zip_url']);
        try {
            $manifest = $vectors->parseManifest($extractDir);
            $this->info('Manifest parsed — '.count($manifest['symbols']).' symbol directories available.');

            // Index every key under sets/ once. Shared by all three passes
            // so we don't fan out thousands of HeadObject calls to MinIO.
            $existing = array_flip($disk->allFiles('sets'));

            $this->upgradeFallbacks($disk, $existing, $manifest, $vectors);
            $this->syncFromMtgVectors($disk, $existing, $extractDir, $manifest, $vectors, $forceOverwrite);
            $this->backfillFromScryfall($scryfall, $disk, $existing);

            $disk->put(self::VERSION_KEY, $release['tag']);
        } finally {
            $vectors->cleanup($extractDir);
        }

        $this->syncSymbols($scryfall);

        return self::SUCCESS;
    }

    private function syncCardBack(ScryfallService $scryfall, Filesystem $disk): void
    {
        if ($disk->exists('card-back.jpg')) {
            $this->info('Card back already exists, skipping.');

            return;
        }
        if ($scryfall->downloadToDisk('https://cards.scryfall.io/back.png', self::DISK, 'card-back.jpg')) {
            $this->info('Downloaded card back.');
        } else {
            $this->error('Failed to download card back.');
        }
    }

    /**
     * Drop stale Scryfall-fallback files for any set that mtg-vectors now
     * covers. The main loop then uploads the proper per-rarity SVGs. Keeps
     * the bucket converging toward the best-available source over time.
     *
     * @param  array<string, int|true>  $existing
     * @param  array{symbols: array<string, array<int, string>>, aliases: array<string, string>, routes: array<string, string>}  $manifest
     */
    private function upgradeFallbacks(Filesystem $disk, array &$existing, array $manifest, MtgVectorsService $vectors): void
    {
        $markerSuffix  = '/'.self::FALLBACK_MARKER;
        $prefixLen     = strlen('sets/');
        $markerSfxLen  = strlen($markerSuffix);
        $upgraded      = 0;

        foreach (array_keys($existing) as $key) {
            if (! str_ends_with($key, $markerSuffix)) {
                continue;
            }
            $setCode = substr($key, $prefixLen, -$markerSfxLen);
            if ($vectors->resolveSymbol($setCode, $manifest) === null) {
                continue;
            }

            foreach (self::RARITIES as $rarity) {
                $path = "sets/{$setCode}/{$rarity}.svg";
                if (isset($existing[$path])) {
                    $disk->delete($path);
                    unset($existing[$path]);
                }
            }
            $disk->delete($key);
            unset($existing[$key]);
            $upgraded++;
        }

        if ($upgraded > 0) {
            $this->info("Upgraded {$upgraded} set(s) from Scryfall fallback to mtg-vectors coverage.");
        }
    }

    /**
     * Main loop: for every set we know about, resolve to an mtg-vectors
     * symbol directory and upload the per-rarity SVGs. Driven by the
     * sets table (Scryfall-populated) so we only write files for codes
     * the frontend might actually request.
     *
     * @param  array<string, int|true>  $existing
     * @param  array{symbols: array<string, array<int, string>>, aliases: array<string, string>, routes: array<string, string>}  $manifest
     */
    private function syncFromMtgVectors(
        Filesystem $disk,
        array &$existing,
        string $extractDir,
        array $manifest,
        MtgVectorsService $vectors,
        bool $forceOverwrite
    ): void {
        $sets = MtgSet::query()->get(['code']);
        $this->info("Walking {$sets->count()} sets for mtg-vectors coverage.");

        $covered    = 0;
        $uploaded   = 0;
        $skipped    = 0;
        $failed     = 0;

        $this->withProgressBar(
            $sets->all(),
            function (MtgSet $set) use ($disk, &$existing, $extractDir, $manifest, $vectors, $forceOverwrite, &$covered, &$uploaded, &$skipped, &$failed) {
                $setCode = strtoupper($set->code);
                $symCode = $vectors->resolveSymbol($setCode, $manifest);
                if ($symCode === null) {
                    return;
                }
                $covered++;

                $available = $manifest['symbols'][$symCode] ?? [];
                foreach (self::RARITIES as $rarity) {
                    if (! in_array($rarity, $available, true)) {
                        continue;
                    }

                    $destKey = "sets/{$setCode}/{$rarity}.svg";
                    $srcPath = "{$extractDir}/set/{$symCode}/{$rarity}.svg";

                    if (! $forceOverwrite && isset($existing[$destKey])) {
                        $skipped++;

                        continue;
                    }
                    if (! is_file($srcPath)) {
                        $failed++;

                        continue;
                    }

                    try {
                        $disk->put($destKey, file_get_contents($srcPath));
                        $existing[$destKey] = true;
                        $uploaded++;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::warning("sets:sync mtg-vectors upload failed for {$destKey}: {$e->getMessage()}");
                    }
                }
            }
        );

        $this->newLine(2);
        $this->info("mtg-vectors sync complete. Covered: {$covered}, Uploaded: {$uploaded}, Skipped: {$skipped}, Failed: {$failed}");
    }

    /**
     * Fill per-rarity gaps using Scryfall's icon_svg_uri (already populated
     * in the sets table by BulkSyncService::syncSets). mtg-vectors doesn't
     * cover every set — SLD and a handful of promos are missing — so we
     * download Scryfall's single monochrome icon once and fan it out to
     * C/U/R/M. Rarity colour-coding is lost for these sets, but a usable
     * symbol beats SetSymbol.vue's fallback "?".
     *
     * @param  array<string, int|true>  $existing
     */
    private function backfillFromScryfall(ScryfallService $scryfall, Filesystem $disk, array &$existing): void
    {
        $sets = MtgSet::query()
            ->whereNotNull('icon_svg_uri')
            ->get(['code', 'icon_svg_uri']);

        $this->info("Checking {$sets->count()} sets for missing rarity symbols.");

        $filled = 0;
        $intact = 0;
        $failed = 0;

        $this->withProgressBar(
            $sets->all(),
            function (MtgSet $set) use (&$existing, $disk, $scryfall, &$filled, &$intact, &$failed) {
                $code    = strtoupper($set->code);
                $missing = [];
                foreach (self::RARITIES as $rarity) {
                    if (! isset($existing["sets/{$code}/{$rarity}.svg"])) {
                        $missing[] = $rarity;
                    }
                }

                if (empty($missing)) {
                    $intact++;

                    return;
                }

                $first     = array_shift($missing);
                $firstDest = "sets/{$code}/{$first}.svg";

                if (! $scryfall->downloadToDisk($set->icon_svg_uri, self::DISK, $firstDest)) {
                    $failed++;
                    Log::warning("sets:sync backfill failed to download {$set->icon_svg_uri}");

                    return;
                }
                $existing[$firstDest] = true;

                foreach ($missing as $rarity) {
                    $target = "sets/{$code}/{$rarity}.svg";
                    $disk->copy($firstDest, $target);
                    $existing[$target] = true;
                }

                $markerKey = "sets/{$code}/".self::FALLBACK_MARKER;
                $disk->put($markerKey, '');
                $existing[$markerKey] = true;

                $filled++;
            }
        );

        $this->newLine(2);
        $this->info("Backfill complete. Filled: {$filled}, Intact: {$intact}, Failed: {$failed}");
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
