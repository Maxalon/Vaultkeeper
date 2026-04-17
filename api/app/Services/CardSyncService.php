<?php

namespace App\Services;

use App\Models\UserCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single source of truth for mapping Scryfall card responses onto local
 * `user_cards` rows. Used by the lazy GET /api/collection path AND by the
 * post-import FetchCardTextData job — both go through this service so the
 * field mapping cannot drift between code paths.
 */
class CardSyncService
{
    /** Cards older than this many days are eligible for re-sync. */
    private const STALE_AFTER_DAYS = 30;

    /**
     * Use the batch /cards/collection endpoint when there are at least this
     * many cards. Below the threshold the per-card endpoint is faster
     * because the collection endpoint enforces a 500ms cool-down per chunk
     * while the single endpoint only enforces 100ms.
     */
    private const BATCH_THRESHOLD = 5;

    /** Layouts whose card_faces[] hold front/back data we care about. */
    private const DFC_LAYOUTS = [
        'transform',
        'modal_dfc',
        'double_faced_token',
        'reversible_card',
    ];

    public function __construct(private ScryfallService $scryfall) {}

    /**
     * Returns true if the card has never been synced (image_small is null)
     * or its data is older than STALE_AFTER_DAYS.
     */
    public function needsSync(UserCard $card): bool
    {
        if ($card->image_small === null) {
            return true;
        }

        if ($card->last_scryfall_sync === null) {
            return true;
        }

        return $card->last_scryfall_sync->lt(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * Fetch a single card from Scryfall and apply its data. Used by the
     * lazy detail path (GET /api/collection/{id}).
     */
    public function sync(UserCard $card): UserCard
    {
        try {
            $response = $this->scryfall->fetchCard($card->scryfall_id);
        } catch (Throwable $e) {
            Log::warning("CardSyncService::sync fetch {$card->scryfall_id} failed: {$e->getMessage()}");
            return $card;
        }

        if ($response === null) {
            return $card;
        }

        $this->applyScryfallData($card, $response);

        return $card->refresh();
    }

    /**
     * Fetch and apply data for many cards in one go. Routes to the batch
     * endpoint when there are enough cards to make the 500ms cool-down
     * worth it; otherwise falls back to per-card fetches.
     *
     * @param  iterable<UserCard>  $cards
     */
    public function syncMany(iterable $cards): void
    {
        $cards = $cards instanceof Collection ? $cards : collect($cards);

        if ($cards->isEmpty()) {
            return;
        }

        if ($cards->count() < self::BATCH_THRESHOLD) {
            foreach ($cards as $card) {
                $this->sync($card);
            }
            return;
        }

        $ids = $cards->pluck('scryfall_id')->all();
        $byId = $cards->keyBy('scryfall_id');

        try {
            $results = $this->scryfall->fetchCardCollection($ids);
        } catch (Throwable $e) {
            Log::warning("CardSyncService::syncMany batch fetch failed: {$e->getMessage()}");
            return;
        }

        foreach ($results as $scryfallId => $response) {
            $card = $byId->get($scryfallId);
            if ($card === null) {
                continue;
            }

            try {
                $this->applyScryfallData($card, $response);
            } catch (Throwable $e) {
                Log::warning("CardSyncService::syncMany apply {$scryfallId} failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Map a Scryfall card response onto the local user_cards row and persist
     * it. Public so callers iterating their own batch results (e.g. tests)
     * can reuse the mapping without round-tripping through Scryfall.
     *
     * Updates go through the query builder rather than Eloquent save()
     * because we want a single UPDATE per card; that means JSON columns
     * (colors, legalities) must be json_encoded explicitly — model $casts
     * only fire on save().
     *
     * @param  array<string, mixed>  $response
     */
    public function applyScryfallData(UserCard $card, array $response): void
    {
        $layout = $response['layout'] ?? null;
        $faces  = $response['card_faces'] ?? null;
        $isDfc  = is_array($faces)
            && isset($faces[0], $faces[1])
            && in_array($layout, self::DFC_LAYOUTS, true);

        if ($isDfc) {
            $front = $faces[0];
            $back  = $faces[1];

            // Image URIs may live on the top-level response (e.g. transform
            // cards before 2017) or per-face (modern DFCs). Prefer per-face
            // when present.
            $frontImages = $front['image_uris'] ?? $response['image_uris'] ?? [];
            $backImages  = $back['image_uris'] ?? [];

            $update = [
                'image_small'        => $frontImages['small'] ?? null,
                'image_normal'       => $frontImages['normal'] ?? null,
                'image_large'        => $frontImages['large'] ?? null,
                'image_small_back'   => $backImages['small'] ?? null,
                'image_normal_back'  => $backImages['normal'] ?? null,
                'image_large_back'   => $backImages['large'] ?? null,
                'mana_cost'          => $front['mana_cost'] ?? null,
                'mana_cost_back'     => $back['mana_cost'] ?? null,
                'type_line'          => $front['type_line'] ?? null,
                'type_line_back'     => $back['type_line'] ?? null,
                'oracle_text'        => $front['oracle_text'] ?? null,
                'oracle_text_back'   => $back['oracle_text'] ?? null,
                'power'              => $front['power'] ?? null,
                'toughness'          => $front['toughness'] ?? null,
                'loyalty'            => $front['loyalty'] ?? null,
                'is_dfc'             => true,
            ];
        } else {
            $images = $response['image_uris'] ?? [];

            $update = [
                'image_small'        => $images['small'] ?? null,
                'image_normal'       => $images['normal'] ?? null,
                'image_large'        => $images['large'] ?? null,
                'image_small_back'   => null,
                'image_normal_back'  => null,
                'image_large_back'   => null,
                'mana_cost'          => $response['mana_cost'] ?? null,
                'mana_cost_back'     => null,
                'type_line'          => $response['type_line'] ?? null,
                'type_line_back'     => null,
                'oracle_text'        => $response['oracle_text'] ?? null,
                'oracle_text_back'   => null,
                'power'              => $response['power'] ?? null,
                'toughness'          => $response['toughness'] ?? null,
                'loyalty'            => $response['loyalty'] ?? null,
                'is_dfc'             => false,
            ];
        }

        // Top-level fields shared by all layouts.
        $colors     = $response['colors'] ?? [];
        $legalities = $response['legalities'] ?? null;

        // colors on a DFC may live on the top-level OR per-face — Scryfall
        // returns the unioned color identity at the top level for transform
        // cards, so prefer that when available.
        if ($isDfc && empty($colors)) {
            $faceColors = array_merge(
                $faces[0]['colors'] ?? [],
                $faces[1]['colors'] ?? [],
            );
            $colors = array_values(array_unique($faceColors));
        }

        $update['rarity']             = $response['rarity'] ?? null;
        $update['colors']             = json_encode($colors);
        $update['legalities']         = $legalities !== null ? json_encode($legalities) : null;
        $update['last_scryfall_sync'] = now();

        UserCard::where('scryfall_id', $card->scryfall_id)->update($update);
    }
}
