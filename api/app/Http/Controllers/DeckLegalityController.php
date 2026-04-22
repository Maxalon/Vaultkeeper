<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\DeckIgnoredIllegality;
use App\Services\DeckLegalityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeckLegalityController extends Controller
{
    private const ILLEGALITY_TYPES = [
        'banned_card',
        'color_identity_violation',
        'duplicate_card',
        'invalid_partner',
        'invalid_commander',
        'invalid_companion',
        'deck_size',
        'too_many_cards',
        'not_legal_in_format',
        'orphan_signature_spell',
        'missing_signature_spell',
    ];

    public function __construct(private DeckLegalityService $legality) {}

    public function index(Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $illegalities = $this->legality->check($deck);
        $ignored = $deck->ignoredIllegalities()->get();

        $result = array_map(function (array $ill) use ($ignored) {
            $ill['ignored'] = $this->matchesAny($ill, $ignored);
            return $ill;
        }, $illegalities);

        return response()->json($result);
    }

    public function ignore(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeOwner($deck);

        $data = $request->validate([
            'illegality_type' => ['required', 'string', 'in:'.implode(',', self::ILLEGALITY_TYPES)],
            'scryfall_id_1'   => 'nullable|uuid',
            'scryfall_id_2'   => 'nullable|uuid',
            'oracle_id'       => 'nullable|uuid',
            'expected_count'  => 'nullable|integer|min:0',
        ]);

        $row = DeckIgnoredIllegality::firstOrCreate([
            'deck_id'         => $deck->id,
            'illegality_type' => $data['illegality_type'],
            'scryfall_id_1'   => $data['scryfall_id_1']  ?? null,
            'scryfall_id_2'   => $data['scryfall_id_2']  ?? null,
            'oracle_id'       => $data['oracle_id']      ?? null,
        ], [
            'expected_count'  => $data['expected_count'] ?? null,
        ]);

        return response()->json($row, 201);
    }

    public function unignore(Request $request, Deck $deck): Response
    {
        $this->authorizeOwner($deck);

        $data = $request->validate([
            'illegality_type' => ['required', 'string', 'in:'.implode(',', self::ILLEGALITY_TYPES)],
            'scryfall_id_1'   => 'nullable|uuid',
            'scryfall_id_2'   => 'nullable|uuid',
            'oracle_id'       => 'nullable|uuid',
        ]);

        DeckIgnoredIllegality::query()
            ->where('deck_id', $deck->id)
            ->where('illegality_type', $data['illegality_type'])
            ->where('scryfall_id_1', $data['scryfall_id_1']  ?? null)
            ->where('scryfall_id_2', $data['scryfall_id_2']  ?? null)
            ->where('oracle_id',     $data['oracle_id']      ?? null)
            ->delete();

        return response()->noContent();
    }

    private function authorizeOwner(Deck $deck): void
    {
        abort_if($deck->user_id !== auth()->id(), 403);
    }

    /**
     * @param  iterable<DeckIgnoredIllegality>  $ignored
     */
    private function matchesAny(array $ill, iterable $ignored): bool
    {
        foreach ($ignored as $row) {
            if ($row->illegality_type === $ill['type']
                && $row->scryfall_id_1 === $ill['scryfall_id_1']
                && $row->scryfall_id_2 === $ill['scryfall_id_2']
                && $row->oracle_id     === $ill['oracle_id']
                && ($row->expected_count === null ? $ill['expected_count'] === null
                    : (int) $row->expected_count === (int) $ill['expected_count'])) {
                return true;
            }
        }
        return false;
    }
}
