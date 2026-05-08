<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\Friendship;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Models\UserPrivacySetting;
use App\Services\CardSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view of a friend's collection and deck list.
 *
 * These endpoints are intentionally separate from CollectionController and
 * DeckController so authorization logic for friend-visibility doesn't
 * pollute the owner-only controllers.
 *
 * Access rules:
 *   - caller must have an accepted friendship with {user}
 *   - {user}'s `user_privacy_settings.collection_visibility` must be 'friends'
 *     (not 'private') for GET /users/{user}/collection
 *   - {user}'s `user_privacy_settings.decks_visibility` must be 'friends'
 *     for GET /users/{user}/decks
 *
 * Route definitions (all under auth:api + throttle:120,1):
 *
 *   GET /users/{user}/collection
 *   GET /users/{user}/decks
 */
class UserCollectionController extends Controller
{
    public function __construct(
        private CardSearchService $search,
    ) {}

    /** Sortable columns. Mirrors CollectionController::SORT_FIELDS. */
    private const SORT_FIELDS = [
        'name'             => 'scryfall_cards.name',
        'set_code'         => 'scryfall_cards.set_code',
        'rarity'           => 'scryfall_cards.rarity',
        'collector_number' => 'scryfall_cards.collector_number',
        'condition'        => 'collection_entries.condition',
    ];

    /**
     * GET /api/users/{user}/collection
     *
     * Returns the friend's collection entries (available copies only).
     * Owner-private fields (location, notes, version, source_deck) are
     * intentionally omitted — the viewer is browsing, not managing.
     *
     * Query params:
     *   - q:     Scryfall-syntax search. Falls back to `search` (legacy
     *            name-substring) when q is absent.
     *   - sort:  one of SORT_FIELDS (default: name)
     *   - order: asc|desc (default: asc)
     *
     * Response 200:
     *   { "data": [ { "id": 7, "scryfall_id": "...", "quantity": 2,
     *                 "condition": "NM", "foil": false, "is_etched": false,
     *                 "card": { "id": "...", "name": "...", "type_line": "...",
     *                           "mana_cost": "...", "colors": [...],
     *                           "color_identity": [...], "rarity": "...",
     *                           "set": "...", "set_name": "...",
     *                           "image_uri": "...", "prices": {...} } } ],
     *     "warnings": [] }
     *
     * Responses:
     *   200 — success
     *   403 — not friends, or friend set collection_visibility='private'
     *   404 — user not found
     */
    public function collection(Request $request, int $user): JsonResponse
    {
        $owner = User::findOrFail($user);

        $this->authorizeCollectionRead($request->user(), $owner);

        $sortKey = $request->query('sort', 'name');
        $sortCol = self::SORT_FIELDS[$sortKey] ?? self::SORT_FIELDS['name'];
        $order   = strtolower($request->query('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = CollectionEntry::query()
            ->join('scryfall_cards', 'collection_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
            ->where('collection_entries.user_id', $owner->id)
            ->with(['card.priceRow', 'card.set'])
            // Skip deck-shadow copies — those are owned by a deck and don't
            // belong on a "available copies" listing.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('locations')
                    ->whereColumn('locations.id', 'collection_entries.location_id')
                    ->where('locations.role', Location::ROLE_DECK);
            })
            ->select('collection_entries.*');

        $warnings = [];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $parsed = $this->search->search($q, ['disable_defaults' => true]);
            $warnings = $parsed['warnings'];
            $query->whereIn(
                'scryfall_cards.oracle_id',
                $parsed['builder']->select('oracle_id')
            );
            $printingFilters = $parsed['printing_filters'] ?? [];
            if (! empty($printingFilters['set'])) {
                $query->where('scryfall_cards.set_code', $printingFilters['set']);
            }
            if (! empty($printingFilters['collector_number'])) {
                $query->where('scryfall_cards.collector_number', $printingFilters['collector_number']);
            }
        } elseif ($search = trim((string) $request->query('search', ''))) {
            $query->where('scryfall_cards.name', 'like', "%{$search}%");
        }

        $entries = $query->orderBy($sortCol, $order)->get();

        return response()->json([
            'data'     => $entries->map(fn (CollectionEntry $e) => $this->presentEntry($e))->values(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * GET /api/users/{user}/decks
     *
     * Returns the friend's deck list (deck metadata only, no entries).
     * Response shape mirrors GET /api/decks for frontend reuse.
     *
     * Responses:
     *   200 — success
     *   403 — not friends, or friend set decks_visibility='private'
     *   404 — user not found
     */
    public function decks(Request $request, int $user): JsonResponse
    {
        $owner = User::findOrFail($user);

        $this->authorizeDecksRead($request->user(), $owner);

        // A2 stub — full query implemented in A3.
        return response()->json(['data' => []]);
    }

    // ---------------------------------------------------------------------------
    // Authorization helpers (inline — not using the policy gate to avoid
    // auto-discovery coupling; the checks mirror CollectionEntryPolicy exactly)
    // ---------------------------------------------------------------------------

    private function authorizeCollectionRead(User $viewer, User $owner): void
    {
        if ($viewer->id === $owner->id) {
            // Use /api/collection for your own data, not this endpoint.
            abort(403, 'Use /api/collection to access your own collection.');
        }

        $isFriend = Friendship::query()
            ->accepted()
            ->where('user_a_id', min($viewer->id, $owner->id))
            ->where('user_b_id', max($viewer->id, $owner->id))
            ->exists();

        if (! $isFriend) {
            abort(403, 'You must be an accepted friend to view this collection.');
        }

        $privacy = UserPrivacySetting::where('user_id', $owner->id)->first();
        if ($privacy && $privacy->collection_visibility === 'private') {
            abort(403, 'This user has made their collection private.');
        }
    }

    private function authorizeDecksRead(User $viewer, User $owner): void
    {
        if ($viewer->id === $owner->id) {
            abort(403, 'Use /api/decks to access your own decks.');
        }

        $isFriend = Friendship::query()
            ->accepted()
            ->where('user_a_id', min($viewer->id, $owner->id))
            ->where('user_b_id', max($viewer->id, $owner->id))
            ->exists();

        if (! $isFriend) {
            abort(403, 'You must be an accepted friend to view this user\'s decks.');
        }

        $privacy = UserPrivacySetting::where('user_id', $owner->id)->first();
        if ($privacy && $privacy->decks_visibility === 'private') {
            abort(403, 'This user has made their decks private.');
        }
    }

    // ---------------------------------------------------------------------------
    // Presentation helpers
    // ---------------------------------------------------------------------------

    /**
     * Compact list-row shape for the friend-collection view.
     *
     * The card sub-object uses the field names CardListItem.vue consumes
     * (`set`, `set_name`, `image_uri`) rather than the storage column
     * names, so the SPA can render the same component used for the mock
     * fixture without a transform layer.
     *
     * Owner-private fields (notes, location, version, source_deck) are
     * intentionally excluded.
     *
     * @return array<string, mixed>
     */
    private function presentEntry(CollectionEntry $entry): array
    {
        $card = $entry->card;

        return [
            'id'          => $entry->id,
            'scryfall_id' => $entry->scryfall_id,
            'quantity'    => $entry->quantity,
            'condition'   => $entry->condition,
            'foil'        => (bool) $entry->foil,
            'is_etched'   => (bool) $entry->is_etched,
            'card'        => $card ? $this->presentCard($card) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCard(ScryfallCard $card): array
    {
        return [
            'id'               => $card->scryfall_id,
            'name'             => $card->name,
            'type_line'        => $card->type_line,
            'mana_cost'        => $card->mana_cost,
            'colors'           => $card->colors,
            'color_identity'   => $card->color_identity,
            'rarity'           => $card->rarity,
            'set'              => $card->set_code,
            'set_name'         => $card->set?->name,
            'collector_number' => $card->collector_number,
            'image_uri'        => $card->image_normal,
            'prices'           => $this->presentPrices($card->priceRow),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function presentPrices(?\App\Models\CardPrice $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'eur'         => $row->eur !== null         ? (string) $row->eur         : null,
            'eur_foil'    => $row->eur_foil !== null    ? (string) $row->eur_foil    : null,
            'eur_etched'  => $row->eur_etched !== null  ? (string) $row->eur_etched  : null,
            'captured_on' => $row->captured_on?->toDateString(),
        ];
    }
}
