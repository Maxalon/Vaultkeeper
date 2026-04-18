<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScryfallService
{
    private const BASE = 'https://api.scryfall.com';

    private const HEXPROOF_SET_CATALOG_URL = 'https://api.hexproof.io/symbols/set';

    /** Minimum gap between Scryfall API requests, in microseconds (150ms).
     *  Scryfall's docs say 10/s is their ceiling, but sustained traffic at
     *  exactly 100ms starts drawing 429s during syncOracleTags (hundreds of
     *  requests in quick succession). 150ms = ~6.6/s with comfortable
     *  headroom; the 429 retry below covers anything that still slips through. */
    private const MIN_GAP_US = 150_000;

    /** Minimum gap between Scryfall /cards/collection requests, in microseconds (500ms). */
    private const COLLECTION_MIN_GAP_US = 500_000;

    /** Common headers required on all Scryfall API requests. */
    private const API_HEADERS = [
        'User-Agent' => 'Vaultkeeper/1.0',
        'Accept'     => 'application/json',
    ];

    private float $lastRequestAt = 0.0;

    private float $lastCollectionRequestAt = 0.0;

    public function __construct(private HttpFactory $http) {}

    /**
     * Fetch a single card from Scryfall by scryfall_id.
     *
     * @return array<string, mixed>|null Full card body, or null on 404.
     */
    public function fetchCard(string $scryfallId): ?array
    {
        $this->throttle();

        $response = $this->http
            ->withHeaders(self::API_HEADERS)
            ->get(self::BASE."/cards/{$scryfallId}");

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
     * Fetch many cards in one go via Scryfall's /cards/collection endpoint.
     *
     * Chunks the input into groups of 75 (Scryfall's per-request maximum) and
     * throttles to 500ms between chunks. Cards not found by Scryfall are
     * silently dropped from the returned map.
     *
     * @param  string[]  $scryfallIds
     * @return array<string, array<string, mixed>>  card data keyed by scryfall_id
     */
    public function fetchCardCollection(array $scryfallIds): array
    {
        $results = [];

        foreach (array_chunk($scryfallIds, 75) as $chunk) {
            $this->throttleCollection();

            $identifiers = array_map(static fn (string $id) => ['id' => $id], $chunk);

            $response = $this->http
                ->withHeaders(self::API_HEADERS)
                ->post(self::BASE.'/cards/collection', [
                    'identifiers' => $identifiers,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Scryfall fetchCardCollection failed: status {$response->status()}"
                );
            }

            foreach ((array) $response->json('data', []) as $card) {
                if (is_array($card) && isset($card['id'])) {
                    $results[$card['id']] = $card;
                }
            }
        }

        return $results;
    }

    /**
     * Fetch the full list of Scryfall sets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSets(): array
    {
        $this->throttle();

        $response = $this->http
            ->withHeaders(self::API_HEADERS)
            ->get(self::BASE.'/sets');

        if (! $response->successful()) {
            throw new RuntimeException(
                "Scryfall fetchSets failed: status {$response->status()}"
            );
        }

        return $response->json('data', []);
    }

    /**
     * Fetch the Scryfall symbology catalog (every mana / cost symbol).
     *
     * Each entry has at minimum a `symbol` (e.g. "{W}") and `svg_uri`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSymbology(): array
    {
        $this->throttle();

        $response = $this->http
            ->withHeaders(self::API_HEADERS)
            ->get(self::BASE.'/symbology');

        if (! $response->successful()) {
            throw new RuntimeException(
                "Scryfall fetchSymbology failed: status {$response->status()}"
            );
        }

        return $response->json('data', []);
    }

    /**
     * Fetch the Hexproof set-symbol catalog.
     *
     * Response shape: { SET_CODE: { RARITY_LETTER: svg_url, ... }, ... }
     * Each rarity entry's value is the direct SVG download URL — callers
     * should use those URLs as-is rather than constructing their own.
     *
     * @return array<string, array<string, string>>
     */
    public function fetchSetCatalog(): array
    {
        $response = $this->http->get(self::HEXPROOF_SET_CATALOG_URL);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Hexproof fetchSetCatalog failed: status {$response->status()}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch the Scryfall bulk-data manifest. Returns the `data` array of
     * available bulk files (each with type, download_uri, updated_at, etc.).
     * Caller filters to the desired type (e.g. `default_cards`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBulkDataManifest(): array
    {
        $this->throttle();

        $response = $this->http
            ->withHeaders(self::API_HEADERS)
            ->get(self::BASE.'/bulk-data');

        if (! $response->successful()) {
            throw new RuntimeException(
                "Scryfall fetchBulkDataManifest failed: status {$response->status()}"
            );
        }

        return $response->json('data', []);
    }

    /**
     * Fetch all Scryfall card-id migrations since the given ISO timestamp,
     * paginating internally and returning a flat array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMigrations(string $sinceIso): array
    {
        $migrations = [];
        $page = 1;

        while (true) {
            $this->throttle();

            $response = $this->http
                ->withHeaders(self::API_HEADERS)
                ->get(self::BASE.'/migrations', [
                    'page'  => $page,
                    'since' => $sinceIso,
                ]);

            if ($response->status() === 404) {
                // No migrations match the filter — return whatever we have.
                break;
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Scryfall fetchMigrations failed: status {$response->status()}"
                );
            }

            foreach ((array) $response->json('data', []) as $row) {
                $migrations[] = $row;
            }

            if (! $response->json('has_more', false)) {
                break;
            }

            $page++;
        }

        return $migrations;
    }

    /**
     * Single-page card search. Returns the raw Scryfall response so callers
     * can inspect `has_more` and increment the page counter themselves.
     *
     * @return array<string, mixed>  { data: [...], has_more: bool, total_cards: int, ... }
     */
    public function searchCards(string $query, int $page = 1, string $unique = 'cards'): array
    {
        // Scryfall starts returning 429s once we've hammered the endpoint
        // enough (syncOracleTags makes hundreds of calls in a row). When
        // that happens the window doesn't reset immediately — retrying
        // after a real pause (honouring Retry-After) reliably recovers,
        // instead of dropping the whole tag on the first 429.
        $maxAttempts = 3;

        for ($attempt = 1; ; $attempt++) {
            $this->throttle();

            $response = $this->http
                ->withHeaders(self::API_HEADERS)
                ->get(self::BASE.'/cards/search', [
                    'q'      => $query,
                    'page'   => $page,
                    'unique' => $unique,
                ]);

            if ($response->status() === 404) {
                // Scryfall returns 404 when a query has zero matches. Treat as empty.
                return ['data' => [], 'has_more' => false, 'total_cards' => 0];
            }

            if ($response->status() === 429 && $attempt < $maxAttempts) {
                $delay = max(30, (int) $response->header('Retry-After'));
                Log::warning("Scryfall 429 (q={$query}, page={$page}); sleeping {$delay}s before retry {$attempt}/{$maxAttempts}.");
                sleep($delay);
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Scryfall searchCards (q={$query}, page={$page}) failed: status {$response->status()}"
                );
            }

            return $response->json() ?? ['data' => [], 'has_more' => false];
        }
    }

    /**
     * Download a binary file from an arbitrary URL and write it through the
     * given Laravel filesystem disk. Not rate-limited — intended for CDN
     * asset downloads, not Scryfall API calls.
     *
     * Going through Storage::disk() instead of raw file I/O means the same
     * code path works for local dev (driver=local) and prod / staging
     * (driver=s3 pointing at MinIO or AWS) — the callers don't know or
     * care where the bytes end up.
     */
    public function downloadToDisk(string $url, string $disk, string $path): bool
    {
        try {
            $response = $this->http->get($url);

            if (! $response->successful()) {
                return false;
            }

            return Storage::disk($disk)->put($path, $response->body());
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

    /**
     * Sleep long enough to respect Scryfall's 500ms cool-down between
     * /cards/collection requests (2 req/s). Tracked separately from the
     * single-card endpoint throttle.
     */
    private function throttleCollection(): void
    {
        $now = microtime(true);

        if ($this->lastCollectionRequestAt > 0.0) {
            $elapsedUs = ($now - $this->lastCollectionRequestAt) * 1_000_000;
            if ($elapsedUs < self::COLLECTION_MIN_GAP_US) {
                usleep((int) (self::COLLECTION_MIN_GAP_US - $elapsedUs));
            }
        }

        $this->lastCollectionRequestAt = microtime(true);
    }
}
