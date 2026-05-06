<?php

namespace App\Http\Controllers;

use App\Models\CardOracleTag;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Services\DeckEntryActionService;
use App\Services\PhysicalCopyEditService;
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

    public function __construct(
        private DeckController $deckCtrl,
        private DeckEntryActionService $actions,
        private PhysicalCopyEditService $physicalEdits,
    ) {}

    public function index(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $query = DeckEntry::query()
            ->where('deck_id', $deck->id)
            ->with(['card', 'physicalCopy.location:id,name,role']);

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
            'signature_for_entry_id' => [
                'sometimes', 'nullable', 'integer',
                $this->signatureForEntryRule($deck),
            ],
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
            'foil'                   => 'sometimes|nullable|boolean',
            'is_etched'              => 'sometimes|nullable|boolean',
            // Inline picker: 'create_new_copy' delegates the create to
            // DeckEntryActionService::createWithNewCopy so a fresh CE is
            // created in the deck-location and linked in the same write.
            'mode'                   => 'sometimes|in:create_new_copy',
        ]);

        // Mirror PhysicalCopyEditService's mutual exclusion: setting etched
        // forces foil=false. Enforced controller-side, not at the DB level.
        if (! empty($data['is_etched'])) {
            $data['foil'] = false;
        }

        // Inline-picker shortcut: "I just bought it (and I'm putting it
        // in this deck)". Bypasses the regular store path entirely so
        // the linked CE has no review_reason (user-confirmed).
        if (($data['mode'] ?? null) === 'create_new_copy') {
            $entry = $this->actions->createWithNewCopy($deck, [
                'scryfall_id' => $data['scryfall_id'],
                'zone'        => $data['zone'] ?? 'main',
                'quantity'    => $data['quantity'] ?? 1,
                'category'    => $data['category'] ?? $this->autoCategory(
                    ScryfallCard::where('scryfall_id', $data['scryfall_id'])->first()
                ),
            ]);
            return response()->json($this->presentEntryBare($entry->fresh('card')), 201);
        }

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
                'foil'                   => array_key_exists('foil', $data) ? $data['foil'] : null,
                'is_etched'              => array_key_exists('is_etched', $data) ? $data['is_etched'] : null,
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
            'signature_for_entry_id' => [
                'sometimes', 'nullable', 'integer',
                $this->signatureForEntryRule($deck),
            ],
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
            // Per-deck-slot finish, used only on unbound entries. Bound
            // entries source finish from the linked CollectionEntry — edit
            // those through the Physical Copies surface, not here.
            'foil'                   => [
                'sometimes', 'nullable', 'boolean',
                function ($attr, $value, $fail) use ($entry) {
                    if ($entry->physical_copy_id !== null) {
                        $fail('Cannot change finish while a physical copy is bound. Edit the physical copy directly.');
                    }
                },
            ],
            'is_etched'              => [
                'sometimes', 'nullable', 'boolean',
                function ($attr, $value, $fail) use ($entry) {
                    if ($entry->physical_copy_id !== null) {
                        $fail('Cannot change finish while a physical copy is bound. Edit the physical copy directly.');
                    }
                },
            ],
            // Swap which printing this slot represents (e.g. via the
            // sidebar's printing-picker). Only allowed for unbound slots
            // — once a CE is bound, the binding pins the printing, so
            // the user must unbind first. The new printing must share
            // the current entry's oracle_id, so the picker can't be
            // abused to swap to a different card.
            'scryfall_id'            => [
                'sometimes', 'string', 'size:36',
                'exists:scryfall_cards,scryfall_id',
                function ($attr, $value, $fail) use ($entry) {
                    if ($entry->physical_copy_id !== null) {
                        $fail('Cannot change printing while a physical copy is bound. Unbind first.');
                        return;
                    }
                    $newOracle = ScryfallCard::where('scryfall_id', $value)->value('oracle_id');
                    $curOracle = ScryfallCard::where('scryfall_id', $entry->scryfall_id)->value('oracle_id');
                    if ($newOracle === null || $curOracle === null || $newOracle !== $curOracle) {
                        $fail('The selected printing must be of the same card.');
                    }
                },
            ],
            // Inline picker hooks. `mode=create_new_copy` overrides the
            // observer's "grow → wanted" default by minting a CE in the
            // deck-location for the gained copies (or binding the whole
            // unbound slot when no quantity change is specified).
            // `discard=true` overrides the "shrink → pending" default by
            // dropping the freed copies outright.
            'mode'                   => 'sometimes|in:create_new_copy',
            'discard'                => 'sometimes|boolean',
        ]);

        // Mirror PhysicalCopyEditService's mutual exclusion: setting etched
        // forces foil=false. Enforced controller-side, not at the DB level.
        if (! empty($data['is_etched'])) {
            $data['foil'] = false;
        }

        $mode    = $data['mode']    ?? null;
        $discard = (bool) ($data['discard'] ?? false);

        if ($mode === 'create_new_copy') {
            // "I bought it" — either bind the existing quantity (no qty
            // change in the patch) or grow by the delta and back the
            // delta with a fresh CE.
            $newQty = array_key_exists('quantity', $data) ? (int) $data['quantity'] : (int) $entry->quantity;
            $delta  = $newQty - (int) $entry->quantity;
            if ($delta > 0) {
                $entry = $this->actions->growWithNewCopy($entry, $delta);
            } elseif ($delta === 0 && $entry->physical_copy_id === null) {
                $entry = $this->actions->bindAsNewCopy($entry);
            } else {
                throw ValidationException::withMessages([
                    'mode' => ['create_new_copy is only valid when the slot is unbound or the quantity is increasing.'],
                ]);
            }
            return response()->json($this->presentEntryBare($entry->fresh('card')));
        }

        if ($discard) {
            // "Sold or discarded" shrink — must come with a quantity
            // strictly less than the current one.
            if (! array_key_exists('quantity', $data) || (int) $data['quantity'] >= (int) $entry->quantity) {
                throw ValidationException::withMessages([
                    'discard' => ['discard=true requires a quantity strictly less than the current one.'],
                ]);
            }
            $delta = (int) $entry->quantity - (int) $data['quantity'];
            $entry = $this->actions->shrinkAndDiscard($entry, $delta);
            return response()->json($this->presentEntryBare($entry->fresh('card')));
        }

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
            // Strip mode-control keys before persisting — they're not
            // database columns. validate() leaves them in $data even
            // though the action-service branches above already ran.
            unset($data['mode'], $data['discard']);
            $entry->update($data);
        });

        return response()->json($this->presentEntryBare($entry->fresh('card')));
    }

    /**
     * POST /api/decks/{deck}/entries/{entry}/edit-physical
     *
     * Edit the bound CE's condition / foil / notes / printing, optionally
     * splitting the slot when `apply_to` is less than the current quantity.
     * Backed by PhysicalCopyEditService — the heavy lifting (CE split,
     * sibling deck_entry creation, observer-suppression flag) lives there.
     */
    public function editPhysical(Request $request, Deck $deck, DeckEntry $entry): JsonResponse
    {
        $this->authorizeOwner($deck);
        abort_if($entry->deck_id !== $deck->id, 404);

        $data = $request->validate([
            'apply_to'    => ['required', 'integer', 'min:1'],
            'version'     => ['sometimes', 'nullable', 'integer', 'min:0'],
            'condition'   => ['sometimes', 'in:NM,LP,MP,HP,DMG'],
            'foil'        => ['sometimes', 'boolean'],
            'is_etched'   => ['sometimes', 'boolean'],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:1000'],
            'scryfall_id' => ['sometimes', 'string', 'size:36', 'exists:scryfall_cards,scryfall_id'],
        ]);

        $result = $this->physicalEdits->edit($deck, $entry, $data);

        return response()->json($this->presentEntryBare(
            $result->fresh(['card', 'physicalCopy.location'])
        ));
    }

    /**
     * "Want one more (or N more) of this card in this deck."
     *
     * Single endpoint behind every "+1" / catalog-drag path: bumps the
     * existing wanted sibling for (deck, scryfall_id, zone) when one
     * exists, or inserts a fresh wanted-only entry when one doesn't.
     * Never touches a bound sibling — bound rows only change quantity
     * via the explicit inline-picker "I bought it" path.
     *
     * Lock order: SELECT ... FOR UPDATE on the parent deck row first
     * (the same row every concurrent caller for this deck contends on),
     * then read the deck_entries. Two parallel callers can't both decide
     * to insert because they serialize on the deck lock.
     */
    public function growWanted(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $data = $request->validate([
            'scryfall_id' => ['required', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'zone'        => ['required', 'in:main,side,maybe'],
            // Bounded: deck_entries.quantity is a SMALLINT/INT in MySQL —
            // a giant delta would overflow the column and surface a raw
            // DB error to the user. 1000 is far more than any real deck
            // would need.
            'delta'       => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'category'    => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $delta = (int) ($data['delta'] ?? 1);
        $zone  = $data['zone'];
        $sid   = $data['scryfall_id'];

        $entry = DB::transaction(function () use ($deck, $sid, $zone, $delta, $data) {
            // Serialize per-deck so two parallel "+1"s on the same
            // (scryfall_id, zone) can't race into duplicate inserts.
            Deck::whereKey($deck->id)->lockForUpdate()->first();

            $sibling = DeckEntry::query()
                ->where('deck_id', $deck->id)
                ->where('scryfall_id', $sid)
                ->where('zone', $zone)
                ->whereNull('physical_copy_id')
                ->whereNotNull('wanted')
                ->lockForUpdate()
                ->first();

            if ($sibling !== null) {
                $sibling->update(['quantity' => $sibling->quantity + $delta]);
                return $sibling;
            }

            $card = ScryfallCard::where('scryfall_id', $sid)->first();
            $category = array_key_exists('category', $data)
                ? $data['category']
                : ($card ? $this->autoCategory($card) : null);

            return DeckEntry::create([
                'deck_id'     => $deck->id,
                'scryfall_id' => $sid,
                'quantity'    => $delta,
                'zone'        => $zone,
                'category'    => $category,
                'wanted'      => $zone,
            ]);
        });

        return response()->json($this->presentEntryBare($entry->fresh('card')), 200);
    }

    public function destroy(Request $request, Deck $deck, DeckEntry $entry): Response
    {
        $this->authorizeOwner($deck);
        abort_if($entry->deck_id !== $deck->id, 404);

        // Inline picker: `?discard=true` drops the entry's bound copy
        // outright instead of routing it to pending. The default (no
        // query string) is the existing observer-driven behaviour.
        $discard = filter_var($request->query('discard', false), FILTER_VALIDATE_BOOLEAN);
        if ($discard && $entry->physical_copy_id !== null) {
            $wasCommander = (bool) $entry->is_commander;
            $commanderId  = $entry->scryfall_id;
            $this->actions->destroyAndDiscard($entry);
            if ($wasCommander) {
                $this->reconcileCommandersAfterRemoval($deck, $commanderId);
            }
            return response()->noContent();
        }

        DB::transaction(function () use ($deck, $entry) {
            $wasCommander = (bool) $entry->is_commander;
            $commanderId  = $entry->scryfall_id;

            // The deletion fires DeckEntryObserver::deleting, which moves
            // an attached physical copy to pending if it was sitting in
            // this deck's deck-location.
            $entry->delete();

            if ($wasCommander) {
                $this->reconcileCommandersAfterRemoval($deck, $commanderId);
            }
        });

        return response()->noContent();
    }

    /**
     * Clear the deleted entry's scryfall_id from whichever commander
     * slot(s) it occupied and recompute the deck's color identity. Used
     * by both the default destroy path and the inline-picker discard
     * branch so they stay consistent.
     */
    private function reconcileCommandersAfterRemoval(Deck $deck, string $commanderId): void
    {
        if ($deck->commander_1_scryfall_id === $commanderId) {
            $deck->commander_1_scryfall_id = null;
        }
        if ($deck->commander_2_scryfall_id === $commanderId) {
            $deck->commander_2_scryfall_id = null;
        }
        $deck->save();
        $this->deckCtrl->recomputeColorIdentity($deck->fresh('commander1', 'commander2'));
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

        return DB::transaction(function () use ($copy, $deckLocation, $needed) {
            // Re-read the source CE under a row lock — the qty / location
            // checks above ran outside the transaction so we have to
            // re-verify under the lock to avoid a TOCTOU split. (E.g. a
            // concurrent bind could have already drained the copy.)
            $locked = CollectionEntry::query()
                ->where('id', $copy->id)
                ->lockForUpdate()
                ->firstOrFail();
            $sourceLocationId = $locked->location_id;
            $available = (int) $locked->quantity;
            if ($available < $needed) {
                throw ValidationException::withMessages([
                    'physical_copy_id' => ["That copy only has {$available} available; this slot needs {$needed}."],
                ]);
            }

            if ($available === $needed) {
                // Whole CE moves into the deck-location — single row update.
                $locked->update(['location_id' => $deckLocation->id]);
                $boundId = $locked->id;
            } else {
                // Split: keep (available − needed) in the source CE, create
                // a new CE with `needed` copies in the deck-location.
                $locked->update(['quantity' => $available - $needed]);
                $newCopy = CollectionEntry::create([
                    'user_id'     => $locked->user_id,
                    'scryfall_id' => $locked->scryfall_id,
                    'location_id' => $deckLocation->id,
                    'quantity'    => $needed,
                    'condition'   => $locked->condition,
                    'foil'        => (bool) $locked->foil,
                    'is_etched'   => (bool) $locked->is_etched,
                    'notes'       => $locked->notes,
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

    /**
     * Validation closure that ensures `signature_for_entry_id` references
     * an entry that already exists in the SAME deck. Without this check
     * a user could point a signature spell at any deck-entry id in the
     * database — the deck still belongs to them, but the FK reaches
     * across users and breaks the legality engine's invariants.
     */
    private function signatureForEntryRule(Deck $deck): \Closure
    {
        return function ($attr, $value, $fail) use ($deck) {
            if ($value === null) return;
            $ok = DeckEntry::where('id', $value)
                ->where('deck_id', $deck->id)
                ->exists();
            if (! $ok) $fail('The signature_for_entry_id must belong to this deck.');
        };
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
        // Bound-CE detail block — drives the Physical Copies tab and any
        // sidebar surface that wants to show the actual condition/foil/
        // notes the slot is backed by.
        $physical = null;
        if ($entry->physical_copy_id !== null) {
            $copy = $entry->relationLoaded('physicalCopy')
                ? $entry->physicalCopy
                : $entry->physicalCopy()->with('location:id,name,role')->first();
            if ($copy !== null) {
                $physical = [
                    'id'           => $copy->id,
                    'quantity'     => (int) $copy->quantity,
                    'condition'    => $copy->condition,
                    'foil'         => (bool) $copy->foil,
                    'is_etched'    => (bool) $copy->is_etched,
                    'notes'        => $copy->notes,
                    'location_id'  => $copy->location_id,
                    'location_name'=> $copy->location?->name,
                    'version'      => (int) ($copy->version ?? 0),
                    'review_reason'=> $copy->review_reason?->value,
                ];
            }
        }
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
            'foil'                   => $entry->foil,
            'is_etched'              => $entry->is_etched,
            'physical_copy'          => $physical,
            'scryfall_card'          => $card ? [
                'scryfall_id'    => $card->scryfall_id,
                'oracle_id'      => $card->oracle_id,
                'name'           => $card->name,
                'set_code'       => $card->set_code,
                'collector_number' => $card->collector_number,
                'rarity'         => $card->rarity,
                'type_line'      => $card->type_line,
                'mana_cost'      => $card->mana_cost,
                'cmc'            => $card->cmc,
                'colors'         => $card->colors,
                'color_identity' => $card->color_identity,
                'produced_mana'  => $card->produced_mana,
                'oracle_text'    => $card->oracle_text,
                'keywords'       => $card->keywords,
                'finishes'       => $card->finishes,
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
