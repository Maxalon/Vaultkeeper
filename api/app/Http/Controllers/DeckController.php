<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\DeckIgnoredIllegality;
use App\Models\ScryfallCard;
use App\Services\DeckLegalityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DeckController extends Controller
{
    private const FORMATS = ['commander', 'oathbreaker', 'pauper', 'standard', 'modern'];

    public function __construct(private DeckLegalityService $legality) {}

    public function index(): JsonResponse
    {
        $userId = auth()->id();
        $decks = Deck::query()
            ->where('user_id', $userId)
            ->with(['commander1:scryfall_id,name,image_small,image_normal,color_identity,commander_game_changer',
                    'commander2:scryfall_id,name,image_small,image_normal,color_identity,commander_game_changer',
                    'companion:scryfall_id,name,image_small,image_normal,color_identity,keywords'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $deckIds = $decks->pluck('id')->all();

        $entryCounts = DeckEntry::query()
            ->whereIn('deck_id', $deckIds)
            ->where('zone', 'main')
            ->select('deck_id', DB::raw('SUM(quantity) AS total'))
            ->groupBy('deck_id')
            ->pluck('total', 'deck_id');

        $ignoredByDeck = DeckIgnoredIllegality::query()
            ->whereIn('deck_id', $deckIds)
            ->get()
            ->groupBy('deck_id');

        return response()->json(
            $decks->map(function (Deck $deck) use ($entryCounts, $ignoredByDeck) {
                $illegalities = $this->legality->check($deck);
                $ignored = $ignoredByDeck->get($deck->id, collect());
                $active = 0;
                foreach ($illegalities as $ill) {
                    if (! $this->isIgnored($ill, $ignored)) {
                        $active++;
                    }
                }

                return [
                    'id'              => $deck->id,
                    'name'            => $deck->name,
                    'format'          => $deck->format,
                    'description'     => $deck->description,
                    'color_identity'  => $deck->color_identity,
                    'group_id'        => $deck->group_id,
                    'sort_order'      => $deck->sort_order,
                    'is_archived'     => $deck->is_archived,
                    'entry_count'     => (int) ($entryCounts[$deck->id] ?? 0),
                    'illegality_count'=> $active,
                    'commander1'      => $this->presentCommander($deck->commander1),
                    'commander2'      => $this->presentCommander($deck->commander2),
                    'companion'       => $this->presentCompanion($deck->companion),
                ];
            })->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'format'                  => ['required', 'string', 'in:'.implode(',', self::FORMATS)],
            'description'             => 'nullable|string',
            'commander_1_scryfall_id' => ['nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'commander_2_scryfall_id' => ['nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'companion_scryfall_id'   => ['nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
        ]);

        $deck = DB::transaction(function () use ($data) {
            $deck = Deck::create([
                'user_id'                 => auth()->id(),
                'name'                    => $data['name'],
                'format'                  => $data['format'],
                'description'             => $data['description'] ?? null,
                'commander_1_scryfall_id' => $data['commander_1_scryfall_id'] ?? null,
                'commander_2_scryfall_id' => $data['commander_2_scryfall_id'] ?? null,
                'companion_scryfall_id'   => $data['companion_scryfall_id']   ?? null,
            ]);
            $this->syncCommanderEntries($deck);
            $this->recomputeColorIdentity($deck);
            return $deck;
        });

        return response()->json($this->presentDetail($deck->fresh(['entries.card', 'commander1', 'commander2', 'companion'])), 201);
    }

    public function show(Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);
        return response()->json($this->presentDetail(
            $deck->load(['entries.card', 'commander1', 'commander2', 'companion', 'ignoredIllegalities'])
        ));
    }

    public function update(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $data = $request->validate([
            'name'                    => 'sometimes|string|max:100',
            'format'                  => ['sometimes', 'string', 'in:'.implode(',', self::FORMATS)],
            'description'             => 'sometimes|nullable|string',
            'is_archived'             => 'sometimes|boolean',
            'commander_1_scryfall_id' => ['sometimes', 'nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'commander_2_scryfall_id' => ['sometimes', 'nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'companion_scryfall_id'   => ['sometimes', 'nullable', 'uuid', 'exists:scryfall_cards,scryfall_id'],
            'group_id'                => ['sometimes', 'nullable', 'integer'],
            'sort_order'              => 'sometimes|integer',
        ]);

        $commandersChanged = array_key_exists('commander_1_scryfall_id', $data)
                           || array_key_exists('commander_2_scryfall_id', $data);

        DB::transaction(function () use ($deck, $data, $commandersChanged) {
            $deck->fill($data)->save();

            if ($commandersChanged) {
                $this->syncCommanderEntries($deck);
                $this->recomputeColorIdentity($deck);
            }
        });

        return response()->json($this->presentDetail(
            $deck->fresh(['entries.card', 'commander1', 'commander2', 'companion', 'ignoredIllegalities'])
        ));
    }

    public function destroy(Deck $deck): Response
    {
        $this->authorizeOwner($deck);
        $deck->delete();
        return response()->noContent();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers — also used by DeckEntryController via the same service
    // methods below, or inlined where the logic is scoped to decks only.
    // ─────────────────────────────────────────────────────────────────────

    private function authorizeOwner(Deck $deck): void
    {
        abort_if($deck->user_id !== auth()->id(), 403);
    }

    /**
     * Make sure a deck_entries row exists for each commander slot and the
     * is_commander flag reflects the current deck columns. This is the
     * source-of-truth sync: decks.commander_* drives, entries mirror.
     */
    public function syncCommanderEntries(Deck $deck): void
    {
        $commanderIds = array_filter([
            $deck->commander_1_scryfall_id,
            $deck->commander_2_scryfall_id,
        ]);

        // Clear is_commander from any entry that's no longer a commander.
        DeckEntry::where('deck_id', $deck->id)
            ->where('is_commander', true)
            ->whereNotIn('scryfall_id', $commanderIds)
            ->update(['is_commander' => false]);

        // For each current commander, ensure an entry exists with the flag.
        foreach ($commanderIds as $scryfallId) {
            $entry = DeckEntry::firstOrCreate(
                ['deck_id' => $deck->id, 'scryfall_id' => $scryfallId],
                ['quantity' => 1, 'zone' => 'main', 'is_commander' => true],
            );
            if (! $entry->is_commander || $entry->zone !== 'main') {
                $entry->update(['is_commander' => true, 'zone' => 'main']);
            }
        }
    }

    /**
     * Compute the deck's color_identity from its commander(s). Stores the
     * WUBRG-sorted canonical string (e.g. "WUB") on the deck row.
     */
    public function recomputeColorIdentity(Deck $deck): void
    {
        $colors = array_merge(
            (array) ($deck->commander1->color_identity ?? []),
            (array) ($deck->commander2->color_identity ?? []),
        );
        $order = ['W' => 0, 'U' => 1, 'B' => 2, 'R' => 3, 'G' => 4];
        $upper = array_map('strtoupper', $colors);
        $unique = array_values(array_unique($upper));
        usort($unique, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $deck->update(['color_identity' => $unique ? implode('', $unique) : null]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presenters
    // ─────────────────────────────────────────────────────────────────────

    private function presentCommander(?ScryfallCard $card): ?array
    {
        if ($card === null) {
            return null;
        }
        return [
            'scryfall_id'            => $card->scryfall_id,
            'name'                   => $card->name,
            'image_small'            => $card->image_small,
            'image_normal'           => $card->image_normal,
            'color_identity'         => $card->color_identity,
            'commander_game_changer' => (bool) $card->commander_game_changer,
        ];
    }

    private function presentCompanion(?ScryfallCard $card): ?array
    {
        if ($card === null) {
            return null;
        }
        return [
            'scryfall_id'    => $card->scryfall_id,
            'name'           => $card->name,
            'image_small'    => $card->image_small,
            'image_normal'   => $card->image_normal,
            'color_identity' => $card->color_identity,
            'keywords'       => $card->keywords,
        ];
    }

    private function presentDetail(Deck $deck): array
    {
        $illegalities = $this->legality->check($deck);
        $ignored = $deck->relationLoaded('ignoredIllegalities')
            ? $deck->ignoredIllegalities
            : $deck->ignoredIllegalities()->get();

        $entries = $deck->relationLoaded('entries') ? $deck->entries : $deck->entries()->with('card')->get();

        return [
            'id'                     => $deck->id,
            'name'                   => $deck->name,
            'format'                 => $deck->format,
            'description'            => $deck->description,
            'color_identity'         => $deck->color_identity,
            'is_archived'            => $deck->is_archived,
            'group_id'               => $deck->group_id,
            'sort_order'             => $deck->sort_order,
            'commander1'             => $this->presentCommander($deck->commander1),
            'commander2'             => $this->presentCommander($deck->commander2),
            'companion'              => $this->presentCompanion($deck->companion),
            'companion_scryfall_id'  => $deck->companion_scryfall_id,
            'entries_by_zone'        => [
                'main'  => $entries->where('zone', 'main')->values()->map(fn ($e) => $this->presentEntry($e)),
                'side'  => $entries->where('zone', 'side')->values()->map(fn ($e) => $this->presentEntry($e)),
                'maybe' => $entries->where('zone', 'maybe')->values()->map(fn ($e) => $this->presentEntry($e)),
            ],
            'illegalities'           => array_map(function ($ill) use ($ignored) {
                $ill['ignored'] = $this->isIgnored($ill, $ignored);
                return $ill;
            }, $illegalities),
            'ignored_illegalities' => $ignored->map(fn ($row) => $row->only([
                'id', 'illegality_type', 'scryfall_id_1', 'scryfall_id_2', 'oracle_id', 'expected_count',
            ]))->values(),
        ];
    }

    private function presentEntry(DeckEntry $entry): array
    {
        $card = $entry->card;
        return [
            'id'                     => $entry->id,
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
            'card'                   => $card ? [
                'name'            => $card->name,
                'set_code'        => $card->set_code,
                'rarity'          => $card->rarity,
                'type_line'       => $card->type_line,
                'mana_cost'       => $card->mana_cost,
                'colors'          => $card->colors,
                'color_identity'  => $card->color_identity,
                'image_small'     => $card->image_small,
                'image_normal'    => $card->image_normal,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $ill
     * @param  iterable<DeckIgnoredIllegality>  $ignored
     */
    private function isIgnored(array $ill, iterable $ignored): bool
    {
        foreach ($ignored as $row) {
            if ($row->illegality_type === $ill['type']
                && $row->scryfall_id_1  === $ill['scryfall_id_1']
                && $row->scryfall_id_2  === $ill['scryfall_id_2']
                && $row->oracle_id      === $ill['oracle_id']
                && (int) $row->expected_count === (int) $ill['expected_count']) {
                return true;
            }
        }
        return false;
    }
}
