<?php

namespace Tests\Feature;

use App\Services\DeckImportService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression: Archidekt's deck-listing endpoint returns pagination cursors
 * with an `http://` scheme. The SSRF guard in `listArchidektUserDecks`
 * required `https://`, so pagination silently terminated after page 1 and
 * users importing 100+ decks only saw the first 48.
 */
class ArchidektListingPaginationTest extends TestCase
{
    public function test_follows_pagination_when_next_cursor_uses_http_scheme(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, 'page=2')) {
                return Http::response([
                    'count' => 3,
                    'next' => null,
                    'results' => [
                        ['id' => 3, 'name' => 'Page2 Deck C', 'parentFolderId' => null],
                    ],
                ]);
            }

            return Http::response([
                'count' => 3,
                // Mirrors the live Archidekt API: next URL is http://, not https://.
                'next' => 'http://archidekt.com/api/decks/v3/?ownerUsername=foo&page=2&pageSize=48',
                'results' => [
                    ['id' => 1, 'name' => 'Page1 Deck A', 'parentFolderId' => null],
                    ['id' => 2, 'name' => 'Page1 Deck B', 'parentFolderId' => null],
                ],
            ]);
        });

        $decks = app(DeckImportService::class)->listArchidektUserDecks('foo');

        $this->assertCount(3, $decks);
        $this->assertSame([1, 2, 3], array_column($decks, 'id'));
    }
}
