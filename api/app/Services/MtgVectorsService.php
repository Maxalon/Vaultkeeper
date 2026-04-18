<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Source of truth for MTG set-symbol SVGs. Wraps the mtg-vectors GitHub
 * release (https://github.com/Investigamer/mtg-vectors) which ships an
 * optimized.zip containing per-rarity SVGs plus a manifest.json mapping
 * set codes → symbol codes (aliases + routes for promo / arena / duel
 * deck codes that share another set's art).
 *
 * Each sync run fetches the latest release, so upstream fixes and newly
 * released sets are picked up without any change on our side.
 */
class MtgVectorsService
{
    private const LATEST_RELEASE_URL = 'https://api.github.com/repos/Investigamer/mtg-vectors/releases/latest';

    private const ASSET_NAME = 'mtg-vectors.optimized.zip';

    /** GitHub requires a User-Agent on API requests — 403s without one. */
    private const HTTP_HEADERS = ['User-Agent' => 'Vaultkeeper/1.0'];

    /**
     * @return array{tag: string, zip_url: string}
     */
    public function fetchLatestRelease(): array
    {
        $resp = Http::withHeaders(self::HTTP_HEADERS)->get(self::LATEST_RELEASE_URL);
        if (! $resp->successful()) {
            throw new RuntimeException("mtg-vectors release API failed: status {$resp->status()}");
        }

        $body = $resp->json() ?? [];
        $tag  = $body['tag_name'] ?? null;
        $zipUrl = collect($body['assets'] ?? [])
            ->firstWhere('name', self::ASSET_NAME)['browser_download_url'] ?? null;

        if (! $tag || ! $zipUrl) {
            throw new RuntimeException('mtg-vectors release missing tag or optimized.zip asset');
        }

        return ['tag' => $tag, 'zip_url' => $zipUrl];
    }

    /**
     * Download the optimized.zip into a fresh temp directory and extract it.
     * Returns the extract path — caller must invoke cleanup() once done.
     */
    public function downloadAndExtract(string $zipUrl): string
    {
        $extractDir = sys_get_temp_dir().'/mtg-vectors-'.bin2hex(random_bytes(6));
        File::makeDirectory($extractDir, 0755, true);

        $zipPath = $extractDir.'/optimized.zip';
        $resp = Http::withHeaders(self::HTTP_HEADERS)->timeout(120)->get($zipUrl);
        if (! $resp->successful()) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException("mtg-vectors zip download failed: status {$resp->status()}");
        }
        File::put($zipPath, $resp->body());

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            File::deleteDirectory($extractDir);
            throw new RuntimeException('mtg-vectors zip open failed');
        }
        $zip->extractTo($extractDir);
        $zip->close();

        File::delete($zipPath);

        return $extractDir;
    }

    public function cleanup(string $extractDir): void
    {
        if (is_dir($extractDir)) {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Parse the extracted manifest.json. Returns the symbols map (directory
     * listing + available rarities) plus the alias/route indirection tables.
     *
     * @return array{
     *   symbols: array<string, array<int, string>>,
     *   aliases: array<string, string>,
     *   routes:  array<string, string>,
     * }
     */
    public function parseManifest(string $extractDir): array
    {
        $path = "{$extractDir}/manifest.json";
        if (! File::exists($path)) {
            throw new RuntimeException("mtg-vectors manifest.json missing at {$path}");
        }
        $m = json_decode(File::get($path), true);
        if (! is_array($m) || ! isset($m['set']) || ! is_array($m['set'])) {
            throw new RuntimeException('mtg-vectors manifest.json malformed');
        }

        return [
            'symbols' => $m['set']['symbols'] ?? [],
            'aliases' => $m['set']['aliases'] ?? [],
            'routes'  => $m['set']['routes']  ?? [],
        ];
    }

    /**
     * Resolve a Scryfall set code to the symbol directory that holds its
     * SVGs. Follows aliases (semantic renames like ARENA → MTGA), then
     * routes (borrowed art like FNM → DCI), then falls back to identity.
     * Returns null when mtg-vectors has no coverage for the set — caller
     * is expected to defer to the Scryfall icon fallback.
     *
     * @param  array{aliases: array<string, string>, routes: array<string, string>, symbols: array<string, array<int, string>>}  $manifest
     */
    public function resolveSymbol(string $setCode, array $manifest): ?string
    {
        $c = strtoupper($setCode);
        if (isset($manifest['aliases'][$c])) {
            $c = $manifest['aliases'][$c];
        } elseif (isset($manifest['routes'][$c])) {
            $c = $manifest['routes'][$c];
        }

        return isset($manifest['symbols'][$c]) ? $c : null;
    }
}
