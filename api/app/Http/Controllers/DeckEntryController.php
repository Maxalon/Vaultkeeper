<?php

namespace App\Http\Controllers;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeckEntryController extends Controller
{
    /** Type-line priority used when oracle tags don't match any allowlisted category. */
    private const TYPE_PRIORITY = [
        'Battle', 'Planeswalker', 'Creature', 'Land', 'Instant', 'Sorcery', 'Artifact', 'Enchantment',
    ];

    public function __construct(private DeckController $deckCtrl) {}

    public function index(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $query = DeckEntry::query()
            ->where('deck_id', $deck->id)
            ->with('card');

        if ($zone = $request->query('zone')) {
            $query->where('zone', $zone);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $sort = $request->query('sort', 'name');
        $entries = $this->applySort($query, $sort)->get();

        $scryfallIds = $entries->pluck('scryfall_id')->unique()->all();
        $oracleIds   = $entries->pluck('card.oracle_id')->filter()->unique()->all();

        $owned    = $this->ownedCopiesMap($scryfallIds, auth()->id());
        $committed = $this->committedCopiesMap($scryfallIds, auth()->id());
        $oracleTags = $this->oracleTagsMap($oracleIds);

        return response()->json(
            $entries->map(fn (DeckEntry $e) => $this->presentEntry($e, $owned, $committed, $oracleTags))->values()
        );
    }

    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $data = $request->validate([
            'scryfall_id'            => ['required', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'zone'                   => 'sometimes|in:main,side,maybe',
            'quantity'               => 'sometimes|integer|min:1',
            'category'               => 'sometimes|nullable|string|max:100',
            'is_commander'           => 'sometimes|boolean',
            'is_signature_spell'     => 'sometimes|boolean',
            'signature_for_entry_id' => 'sometimes|nullable|integer',
            'wanted'                 => 'sometimes|nullable|in:main,side,maybe',
            'physical_copy_id'       => [
                'sometimes', 'nullable', 'integer',
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $ok = CollectionEntry::where('id', $value)
                        ->where('user_id', auth()->id())
                        ->exists();
                    if (! $ok) $fail('The physical_copy_id must belong to the authenticated user.');
                },
            ],
        ]);

        $card = ScryfallCard::where('scryfall_id', $data['scryfall_id'])->first();

        $entry = DB::transaction(function () use ($deck, $data, $card) {
            $category = $data['category'] ?? $this->autoCategory($card);

            $entry = DeckEntry::create([
                'deck_id'                => $deck->id,
                'scryfall_id'            => $data['scryfall_id'],
                'quantity'               => $data['quantity'] ?? 1,
                'zone'                   => $data['zone'] ?? 'main',
                'category'               => $category,
                'is_commander'           => (bool) ($data['is_commander'] ?? false),
                'is_signature_spell'     => (bool) ($data['is_signature_spell'] ?? false),
                'signature_for_entry_id' => $data['signature_for_entry_id'] ?? null,
                'wanted'                 => $data['wanted'] ?? null,
                'physical_copy_id'       => $data['physical_copy_id'] ?? null,
            ]);

            if (! empty($data['is_commander'])) {
                $this->promoteToCommanderSlot($deck, $data['scryfall_id']);
            }

            return $entry;
        });

        return response()->json($this->presentEntryBare($entry->fresh('card')), 201);
    }

    public function update(Request $request, Deck $deck, DeckEntry $entry): JsonResponse
    {
        $this->authorizeOwner($deck);
        abort_if($entry->deck_id !== $deck->id, 404);

        $data = $request->validate([
            'zone'                   => 'sometimes|in:main,side,maybe',
            'quantity'               => 'sometimes|integer|min:1',
            'category'               => 'sometimes|nullable|string|max:100',
            'is_signature_spell'     => 'sometimes|boolean',
            'signature_for_entry_id' => 'sometimes|nullable|integer',
            'wanted'                 => 'sometimes|nullable|in:main,side,maybe',
            'physical_copy_id'       => [
                'sometimes', 'nullable', 'integer',
                function ($attr, $value, $fail) {
                    if ($value === null) return;
                    $ok = CollectionEntry::where('id', $value)
                        ->where('user_id', auth()->id())
                        ->exists();
                    if (! $ok) $fail('The physical_copy_id must belong to the authenticated user.');
                },
            ],
        ]);

        // Bind path: when the request is binding the slot to a physical
        // copy from outside the deck-location, the picked copy needs to
        // *move into* this deck's deck-location (and possibly split, if
        // the source CE has more copies than the slot needs). Resolves to
        // the CE that should actually be referenced by physical_copy_id.
        // Reads the slot quantity from the patch payload when present so
        // a combined "set qty=4 AND bind" request splits to the new size.
        if (array_key_exists('physical_copy_id', $data) && $data['physical_copy_id'] !== null
            && $data['physical_copy_id'] !== $entry->physical_copy_id) {
            $slotQuantity = (int) ($data['quantity'] ?? $entry->quantity);
            $data['physical_copy_id'] = $this->bindPhysicalCopy(
                deck:     $deck,
                entry:    $entry,
                copyId:   (int) $data['physical_copy_id'],
                quantity: max(1, $slotQuantity),
            );
        }

        DB::transaction(function () use ($entry, $data) {
            $entry->update($data);
        });

        return response()->json($this->presentEntryBare($entry->fresh('card')));
    }

    public function destroy(Deck $deck, DeckEntry $entry): Response
    {
        $this->authorizeOwner($deck);
        abort_if($entry->deck_id !== $deck->id, 404);

        DB::transaction(function () use ($deck, $entry) {
            $wasCommander = (bool) $entry->is_commander;
            $commanderId  = $entry->scryfall_id;

            // The deletion fires DeckEntryObserver::deleting, which moves
            // an attached physical copy to pending if it was sitting in
            // this deck's deck-location.
            $entry->delete();

            if ($wasCommander) {
                if ($deck->commander_1_scryfall_id === $commanderId) {
                    $deck->commander_1_scryfall_id = null;
                }
                if ($deck->commander_2_scryfall_id === $commanderId) {
                    $deck->commander_2_scryfall_id = null;
                }
                $deck->save();
                $this->deckCtrl->recomputeColorIdentity($deck->fresh('commander1', 'commander2'));
            }
        });

        return response()->noContent();
    }

    /**
     * Move the picked copy into the deck's deck-location, splitting the
     * source CE if it has more copies than the slot needs. Returns the
     * collection_entry id the deck_entry should reference (the existing
     * row when the whole CE moved over, or a freshly-created one when we
     * had to split).
     *
     * Already-in-deck-location copies (e.g. picking the same CE the slot
     * is currently bound to) are returned as-is. Picking a copy already
     * in *another* deck's deck-location is rejected — those are owned by
     * that deck and shouldn't silently re-bind to a second slot.
     */
    private function bindPhysicalCopy(Deck $deck, DeckEntry $entry, int $copyId, int $quantity): int
    {
        $copy = CollectionEntry::query()
            ->where('id', $copyId)
            ->where('user_id', $deck->user_id)
            ->first();
        if ($copy === null) {
            throw ValidationException::withMessages([
                'physical_copy_id' => ['The physical_copy_id must belong to the authenticated user.'],
            ]);
        }

        $deckLocation = Location::query()
            ->where('deck_id', $deck->id)
            ->where('role', Location::ROLE_DECK)
            ->first();
        if ($deckLocation === null) {
            // Should never happen — DeckObserver creates the deck-location
            // on Deck::create. Defensive only.
            throw ValidationException::withMessages([
                'physical_copy_id' => ['Deck location not found; cannot bind copy.'],
            ]);
        }

        // Picking a copy that's already in this deck's deck-location is a
        // no-op move — just return its id.
        if ($copy->location_id === $deckLocation->id) {
            return $copy->id;
        }

        // Don't let a copy that lives in *another* deck's deck-location be
        // grabbed silently — that would break the source deck's "owned"
        // invariant. The user has to release it from that deck first.
        if ($copy->location_id !== null) {
            $sourceDeckLocation = Location::query()
                ->where('id', $copy->location_id)
                ->where('role', Location::ROLE_DECK)
                ->first();
            if ($sourceDeckLocation !== null && $sourceDeckLocation->deck_id !== $deck->id) {
                throw ValidationException::withMessages([
                    'physical_copy_id' => ['That copy is already assigned to another deck.'],
                ]);
            }
        }

        $needed = max(1, $quantity);
        $available = (int) $copy->quantity;
        if ($available < $needed) {
            throw ValidationException::withMessages([
                'physical_copy_id' => ["That copy only has {$available} available; this slot needs {$needed}."],
            ]);
        }

        return DB::transaction(function () use ($copy, $deckLocation, $needed, $available) {
            $sourceLocationId = $copy->location_id;

            if ($available === $needed) {
                // Whole CE moves into the deck-location — single row update.
                $copy->update(['location_id' => $deckLocation->id]);
                $boundId = $copy->id;
            } else {
                // Split: keep (available − needed) in the source CE, create
                // a new CE with `needed` copies in the deck-location.
                $copy->update(['quantity' => $available - $needed]);
                $newCopy = CollectionEntry::create([
                    'user_id'      => $copy->user_id,
                    'scryfall_id'  => $copy->scryfall_id,
                    'location_id'  => $deckLocation->id,
                    'quantity'     => $needed,
                    'condition'    => $copy->condition,
                    'foil'         => (bool) $copy->foil,
                    'notes'        => $copy->notes,
                    'needs_review' => false,
                ]);
                $boundId = $newCopy->id;
            }

            // Refresh set_codes on the source location so the sidebar
            // chip drops codes the moved-out card was the last of.
            if ($sourceLocationId !== null) {
                Location::find($sourceLocationId)?->refreshSetCodes();
            }
            $deckLocation->refreshSetCodes();

            return $boundId;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Category resolution
    // ─────────────────────────────────────────────────────────────────────

    public function autoCategory(ScryfallCard $card): ?string
    {
        $allowlist = (array) config('scryfall.oracle_tags', []);
        if ($card->oracle_id) {
            $tags = CardOracleTag::where('oracle_id', $card->oracle_id)->pluck('tag')->all();
            foreach ($allowlist as $candidate) {
                if (in_array($candidate, $tags, true)) {
                    return $candidate;
                }
            }
        }

        $typeLine = $card->type_line ?? '';
        foreach (self::TYPE_PRIORITY as $type) {
            if (str_contains($typeLine, $type)) {
                return strtolower($type);
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Commander slot promotion (is_commander=true on store)
    // ─────────────────────────────────────────────────────────────────────

    private function promoteToCommanderSlot(Deck $deck, string $scryfallId): void
    {
        if ($deck->commander_1_scryfall_id === null) {
            $deck->commander_1_scryfall_id = $scryfallId;
        } elseif ($deck->commander_2_scryfall_id === null && $deck->commander_1_scryfall_id !== $scryfallId) {
            $deck->commander_2_scryfall_id = $scryfallId;
        } else {
            // Both slots full, or already assigned — nothing to promote.
            return;
        }
        $deck->save();
        $this->deckCtrl->syncCommanderEntries($deck);
        $this->deckCtrl->recomputeColorIdentity($deck->fresh('commander1', 'commander2'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Owned / committed aggregate maps (single query each, hash-joined)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $scryfallIds
     * @return array<string, int>  scryfall_id => owned copy count
     */
    private function ownedCopiesMap(array $scryfallIds, int $userId): array
    {
        if (empty($scryfallIds)) return [];
        return CollectionEntry::query()
            ->select('scryfall_id', DB::raw('SUM(quantity) AS total'))
            ->where('user_id', $userId)
            ->whereIn('scryfall_id', $scryfallIds)
            ->groupBy('scryfall_id')
            ->pluck('total', 'scryfall_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<int, string>  $scryfallIds
     * @return array<string, int>  scryfall_id => count of deck_entries
     *                             committed to specific physical copies of
     *                             this scryfall_id across the user's decks
     */
    private function committedCopiesMap(array $scryfallIds, int $userId): array
    {
        if (empty($scryfallIds)) return [];
        return DeckEntry::query()
            ->join('collection_entries', 'deck_entries.physical_copy_id', '=', 'collection_entries.id')
            ->join('decks', 'deck_entries.deck_id', '=', 'decks.id')
            ->where('decks.user_id', $userId)
            ->whereIn('collection_entries.scryfall_id', $scryfallIds)
            ->whereNotNull('deck_entries.physical_copy_id')
            ->select('collection_entries.scryfall_id', DB::raw('COUNT(deck_entries.id) AS committed'))
            ->groupBy('collection_entries.scryfall_id')
            ->pluck('committed', 'scryfall_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<int, string>  $oracleIds
     * @return array<string, array<int, string>>  oracle_id => tags
     */
    private function oracleTagsMap(array $oracleIds): array
    {
        if (empty($oracleIds)) return [];
        return CardOracleTag::query()
            ->whereIn('oracle_id', $oracleIds)
            ->get(['oracle_id', 'tag'])
            ->groupBy('oracle_id')
            ->map(fn ($rows) => $rows->pluck('tag')->values()->all())
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sorting
    // ─────────────────────────────────────────────────────────────────────

    private function applySort($query, string $sort)
    {
        return match ($sort) {
            'cmc'      => $query->join('scryfall_cards', 'deck_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
                                ->orderBy('scryfall_cards.cmc')->select('deck_entries.*'),
            'color'    => $query->join('scryfall_cards', 'deck_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
                                ->orderBy('scryfall_cards.color_identity')->select('deck_entries.*'),
            'rarity'   => $query->join('scryfall_cards', 'deck_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
                                ->orderBy('scryfall_cards.rarity')->select('deck_entries.*'),
            'category' => $query->orderBy('category'),
            default    => $query->join('scryfall_cards', 'deck_entries.scryfall_id', '=', 'scryfall_cards.scryfall_id')
                                ->orderBy('scryfall_cards.name')->select('deck_entries.*'),
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Auth + presenters
    // ─────────────────────────────────────────────────────────────────────

    private function authorizeOwner(Deck $deck): void
    {
        abort_if($deck->user_id !== auth()->id(), 403);
    }

    private function presentEntry(DeckEntry $entry, array $owned, array $committed, array $oracleTags): array
    {
        $base = $this->presentEntryBare($entry);
        $base['owned_copies']     = $owned[$entry->scryfall_id] ?? 0;
        $committedCount           = $committed[$entry->scryfall_id] ?? 0;
        $base['available_copies'] = max(0, $base['owned_copies'] - $committedCount);
        $base['oracle_tags']      = $entry->card?->oracle_id
            ? ($oracleTags[$entry->card->oracle_id] ?? [])
            : [];
        return $base;
    }

    private function presentEntryBare(DeckEntry $entry): array
    {
        $card = $entry->card;
        return [
            'id'                     => $entry->id,
            'deck_id'                => $entry->deck_id,
            'scryfall_id'            => $entry->scryfall_id,
            'quantity'               => $entry->quantity,
            'zone'                   => $entry->zone,
            'category'               => $entry->category,
            'is_commander'           => (bool) $entry->is_commander,
            'is_signature_spell'     => (bool) $entry->is_signature_spell,
            'signature_for_entry_id' => $entry->signature_for_entry_id,
            'wanted'                 => $entry->wanted,
            'physical_copy_id'       => $entry->physical_copy_id,
            'needs_review'           => (bool) $entry->needs_review,
            'scryfall_card'          => $card ? [
                'scryfall_id'    => $card->scryfall_id,
                'oracle_id'      => $card->oracle_id,
                'name'           => $card->name,
                'set_code'       => $card->set_code,
                'rarity'         => $card->rarity,
                'type_line'      => $card->type_line,
                'mana_cost'      => $card->mana_cost,
                'cmc'            => $card->cmc,
                'colors'         => $card->colors,
                'color_identity' => $card->color_identity,
                'produced_mana'  => $card->produced_mana,
                'oracle_text'    => $card->oracle_text,
                'keywords'       => $card->keywords,
                'commander_game_changer' => (bool) $card->commander_game_changer,
                'legalities'     => $card->legalities,
                'is_dfc'         => (bool) $card->is_dfc,
                'image_small'    => $card->image_small,
                'image_normal'   => $card->image_normal,
                'image_small_back'  => $card->image_small_back,
                'image_normal_back' => $card->image_normal_back,
            ] : null,
        ];
    }
}
