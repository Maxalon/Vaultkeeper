<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntry;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only access to the canonical Scryfall reference DB.
 *
 *   GET /api/scryfall-cards/search       — paginated, filterable list
 *   GET /api/scryfall-cards/{scryfallId} — single card detail
 *
 * Each response item includes the authenticated user's `owned_count` and
 * `available_count` (owned minus copies committed to decks).
 */
class ScryfallCardController extends Controller
{
    /** Format keys allowed for the legality filter. */
    private const FORMATS = [
        'standard', 'pioneer', 'modern', 'legacy',
        'vintage', 'commander', 'pauper',
    ];

    /** All Scryfall colour letters in canonical WUBRG order. */
    private const COLORS = ['W', 'U', 'B', 'R', 'G'];

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'              => 'sometimes|nullable|string|max:200',
            'format'         => 'sometimes|nullable|string|in:'.implode(',', self::FORMATS),
            'color_identity' => 'sometimes|nullable|string|regex:/^[wubrgcWUBRGC]+$/',
            'commander'      => 'sometimes|nullable|string|regex:/^[wubrgcWUBRGC]+$/',
            'per_page'       => 'sometimes|integer|min:1|max:75',
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);

        $query = ScryfallCard::query()->with('tags');

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }

        if (! empty($data['format'])) {
            // legalities is a JSON map { "standard": "legal", ... }.
            // whereJsonContains compiles to JSON_CONTAINS, which is whitespace-
            // and ordering-agnostic — direct string compare on a JSON column
            // fails because MySQL re-formats the value with spaces.
            $query->whereJsonContains('legalities->'.$data['format'], 'legal');
        }

        // Exact color identity — card.color_identity must be exactly $sorted,
        // ignoring storage-side whitespace. Compose via JSON_LENGTH + a
        // JSON_CONTAINS per requested colour (and skip everything else).
        if (! empty($data['color_identity'])) {
            $sorted = $this->canonicaliseColorString($data['color_identity']);
            $query->whereRaw('JSON_LENGTH(color_identity) = ?', [count($sorted)]);
            foreach ($sorted as $color) {
                $query->whereJsonContains('color_identity', $color);
            }
        }

        // Commander / EDH "subset" filter — card.color_identity ⊆ allowed set,
        // i.e. NO colour outside `allowed` appears in the card's identity.
        if (! empty($data['commander'])) {
            $allowed = $this->canonicaliseColorString($data['commander']);
            $disallowed = array_values(array_diff(self::COLORS, $allowed));

            // commander=WUBRG ⇒ everything qualifies; no clause needed.
            foreach ($disallowed as $color) {
                $query->whereRaw('NOT JSON_CONTAINS(color_identity, ?)', [
                    json_encode($color),
                ]);
            }
        }

        $page = $query->orderBy('name')->paginate($perPage);

        // One ownership/availability roll-up for the whole page — avoids N+1
        // when the search returns up to 75 cards.
        $userId = auth()->id();
        $ownership = $this->ownershipMap(
            collect($page->items())->pluck('scryfall_id')->all(),
            $userId,
        );

        $page->getCollection()->transform(
            fn (ScryfallCard $card) => $this->present($card, $ownership)
        );

        return response()->json($page);
    }

    public function show(ScryfallCard $scryfallCard): JsonResponse
    {
        $scryfallCard->load('tags');

        $ownership = $this->ownershipMap([$scryfallCard->scryfall_id], auth()->id());

        return response()->json($this->present($scryfallCard, $ownership));
    }

    /**
     * Build an array keyed by scryfall_id with `owned_count` and `available_count`
     * for every card in $scryfallIds. Two queries total, regardless of page size.
     *
     * @param  array<int, string>  $scryfallIds
     * @return array<string, array{owned: int, available: int}>
     */
    private function ownershipMap(array $scryfallIds, ?int $userId): array
    {
        if ($userId === null || empty($scryfallIds)) {
            return [];
        }

        // owned_count = SUM(quantity) for the user's CollectionEntry rows.
        $owned = CollectionEntry::query()
            ->where('user_id', $userId)
            ->whereIn('scryfall_id', $scryfallIds)
            ->selectRaw('scryfall_id, SUM(quantity) AS owned')
            ->groupBy('scryfall_id')
            ->pluck('owned', 'scryfall_id');

        // committed = COUNT of deck slots that point at any of those entries
        // via physical_copy_id. Each committed deck slot consumes one copy.
        $committed = DeckEntry::query()
            ->join('collection_entries', 'collection_entries.id', '=', 'deck_entries.physical_copy_id')
            ->where('collection_entries.user_id', $userId)
            ->whereIn('collection_entries.scryfall_id', $scryfallIds)
            ->selectRaw('collection_entries.scryfall_id AS sid, COUNT(*) AS used')
            ->groupBy('collection_entries.scryfall_id')
            ->pluck('used', 'sid');

        $out = [];
        foreach ($scryfallIds as $sid) {
            $o = (int) ($owned[$sid] ?? 0);
            $c = (int) ($committed[$sid] ?? 0);
            $out[$sid] = [
                'owned'     => $o,
                'available' => max(0, $o - $c),
            ];
        }
        return $out;
    }

    /**
     * Shape a ScryfallCard into the API response. Skips back-face fields
     * for non-DFC cards to keep payloads tight.
     *
     * @param  array<string, array{owned: int, available: int}>  $ownership
     * @return array<string, mixed>
     */
    private function present(ScryfallCard $card, array $ownership): array
    {
        $own = $ownership[$card->scryfall_id] ?? ['owned' => 0, 'available' => 0];

        $out = [
            'scryfall_id'      => $card->scryfall_id,
            'oracle_id'        => $card->oracle_id,
            'name'             => $card->name,
            'set_code'         => $card->set_code,
            'collector_number' => $card->collector_number,
            'rarity'           => $card->rarity,
            'layout'           => $card->layout,
            'is_dfc'           => $card->is_dfc,
            'mana_cost'        => $card->mana_cost,
            'cmc'              => $card->cmc !== null ? (float) $card->cmc : null,
            'colors'           => $card->colors,
            'color_identity'   => $card->color_identity,
            'type_line'        => $card->type_line,
            'oracle_text'      => $card->oracle_text,
            'power'            => $card->power,
            'toughness'        => $card->toughness,
            'loyalty'          => $card->loyalty,
            'legalities'       => $card->legalities,
            'keywords'         => $card->keywords,
            'edhrec_rank'      => $card->edhrec_rank,
            'reserved'         => $card->reserved,
            'image_small'      => $card->image_small,
            'image_normal'     => $card->image_normal,
            'image_large'      => $card->image_large,
            'oracle_tags'      => $card->relationLoaded('tags')
                ? $card->tags->pluck('tag')->values()->all()
                : [],
            'owned_count'      => $own['owned'],
            'available_count'  => $own['available'],
        ];

        if ($card->is_dfc) {
            $out['mana_cost_back']    = $card->mana_cost_back;
            $out['type_line_back']    = $card->type_line_back;
            $out['oracle_text_back']  = $card->oracle_text_back;
            $out['image_small_back']  = $card->image_small_back;
            $out['image_normal_back'] = $card->image_normal_back;
            $out['image_large_back']  = $card->image_large_back;
        }

        return $out;
    }

    /**
     * Normalise a colour-letter string ("wbu") to a sorted canonical array
     * of upper-case letters (["W","U","B"]) so the JSON column comparison
     * matches whatever order Scryfall stored.
     *
     * @return array<int, string>
     */
    private function canonicaliseColorString(string $input): array
    {
        $letters = array_unique(array_map('strtoupper', str_split($input)));
        $order = array_flip(self::COLORS);
        $valid = array_filter($letters, fn ($c) => isset($order[$c]));
        usort($valid, fn ($a, $b) => $order[$a] <=> $order[$b]);
        return array_values($valid);
    }
}
