<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

class ScryfallService
{
    private const BASE = 'https://api.scryfall.com';

    private const MANIFEST_URL = 'https://cdn.jsdelivr.net/gh/Investigamer/mtg-vectors@main/manifest.json';

    /** Minimum gap between Scryfall API requests, in microseconds (100ms). */
    private const MIN_GAP_US = 100_000;

    private float $lastRequestAt = 0.0;

    public function __construct(private HttpFactory $http) {}

    /**
     * Fetch a single card from Scryfall by scryfall_id.
     *
     * @return array<string, mixed>|null Full card body, or null on 404.
     */
    public function fetchCard(string $scryfallId): ?array
    {
        $this->throttle();

        $response = $this->http->get(self::BASE."/cards/{$scryfallId}");

        if ($response->successful()) {
            return $response->json();
        }

        if ($response->status() === 404) {
            return null;
        }

        throw new RuntimeException(
            "Scryfall fetchCard {$scryfallId} failed: status {$response->status()}"
        );
    }

    /**
     * Fetch the full list of Scryfall sets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSets(): array
    {
        $this->throttle();

        $response = $this->http->get(self::BASE.'/sets');

        if (! $response->successful()) {
            throw new RuntimeException(
                "Scryfall fetchSets failed: status {$response->status()}"
            );
        }

        return $response->json('data', []);
    }

    /**
     * Fetch the mtg-vectors manifest listing every set and its available rarities.
     *
     * @return array<string, mixed>
     */
    public function fetchManifest(): array
    {
        $response = $this->http->get(self::MANIFEST_URL);

        if (! $response->successful()) {
            throw new RuntimeException(
                "mtg-vectors fetchManifest failed: status {$response->status()}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Download a binary file from an arbitrary URL to a destination path.
     * Not rate-limited — intended for CDN asset downloads, not Scryfall API calls.
     */
    public function downloadFile(string $url, string $destinationPath): bool
    {
        try {
            $directory = dirname($destinationPath);
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return false;
            }

            $response = $this->http->get($url);

            if (! $response->successful()) {
                return false;
            }

            return file_put_contents($destinationPath, $response->body()) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Sleep long enough to respect Scryfall's requested 50–100ms cool-down
     * between API requests.
     */
    private function throttle(): void
    {
        $now = microtime(true);

        if ($this->lastRequestAt > 0.0) {
            $elapsedUs = ($now - $this->lastRequestAt) * 1_000_000;
            if ($elapsedUs < self::MIN_GAP_US) {
                usleep((int) (self::MIN_GAP_US - $elapsedUs));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
