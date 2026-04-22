<?php

namespace App\Http\Controllers;

use App\Services\DeckImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeckImportController extends Controller
{
    private const FORMATS = ['commander', 'oathbreaker', 'pauper', 'standard', 'modern'];

    public function __construct(private readonly DeckImportService $importer) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source'   => 'required|in:archidekt,moxfield,text',
            'url'      => 'required_if:source,archidekt,moxfield|nullable|url',
            'text'     => 'required_if:source,text|nullable|string|max:50000',
            'name'     => 'required_if:source,text|nullable|string|max:100',
            'format'   => ['required_if:source,text', 'nullable', 'string', 'in:'.implode(',', self::FORMATS)],
            'group_id' => 'sometimes|nullable|integer',
        ]);

        $result = $data['source'] === 'text'
            ? $this->importer->importFromText(
                user:     $request->user(),
                text:     (string) $data['text'],
                name:     (string) $data['name'],
                format:   (string) $data['format'],
                groupId:  $data['group_id'] ?? null,
            )
            : $this->importer->importFromUrl(
                user:    $request->user(),
                url:     (string) $data['url'],
                groupId: $data['group_id'] ?? null,
            );

        return response()->json([
            'deck'     => $this->presentDeck($result['deck']),
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    /**
     * Minimal deck presenter — we only need enough for the frontend to
     * redirect (`id`) and render a confirmation chip (`name`, `format`).
     * The deck route will fetch the full detail right after.
     */
    private function presentDeck($deck): array
    {
        return [
            'id'     => $deck->id,
            'name'   => $deck->name,
            'format' => $deck->format,
        ];
    }
}
