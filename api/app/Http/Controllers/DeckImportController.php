<?php

namespace App\Http\Controllers;

use App\Exceptions\DeckSourceConflictException;
use App\Jobs\BulkImportUserDecksJob;
use App\Services\DeckImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
            // 'auto' => create new, but bail with 409 if a same-source deck
            //          already exists; 'create' = always new (allows
            //          intentional duplicates); 'update' = overwrite the
            //          existing same-source deck in place.
            'mode'     => 'sometimes|nullable|in:auto,create,update',
        ]);

        try {
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
                    mode:    $data['mode'] ?? 'auto',
                );
        } catch (DeckSourceConflictException $e) {
            // 409 surfaces enough info for the frontend to render a
            // confirmation: existing deck name + id, plus the entry count
            // so the user can sanity-check before overwriting.
            return response()->json([
                'message'  => 'You already imported this deck.',
                'existing' => [
                    'id'          => $e->existing->id,
                    'name'        => $e->existing->name,
                    'format'      => $e->existing->format,
                    'updated_at'  => optional($e->existing->updated_at)->toIso8601String(),
                ],
            ], 409);
        }

        return response()->json([
            'deck'     => $this->presentDeck($result['deck']),
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'warnings' => $result['warnings'],
            'action'   => $result['action'] ?? 'created',
        ], 201);
    }

    /**
     * POST /api/decks/import/bulk
     *
     * Kicks off a queued job that imports every public deck for an
     * Archidekt or Moxfield username. Returns immediately with a poll key
     * the frontend uses to track progress via `bulkStatus()`.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source'       => 'required|in:archidekt,moxfield',
            'username'     => 'required|string|max:200',
            // 'skip'   = leave existing same-source decks untouched (default)
            // 'update' = overwrite cards/format from the source for any deck
            //            we've already imported
            'on_duplicate' => 'sometimes|nullable|in:skip,update',
        ]);

        $username = $this->importer->extractUsername($data['source'], $data['username']);
        $jobKey   = (string) Str::uuid();
        $onDup    = $data['on_duplicate'] ?? 'skip';

        Cache::put('bulk-import:'.$jobKey, [
            'state'   => 'queued',
            'message' => "Queued bulk import for {$username}…",
            'updated_at' => now()->toIso8601String(),
        ], 3600);

        BulkImportUserDecksJob::dispatch(
            $request->user()->id,
            $data['source'],
            $username,
            $jobKey,
            $onDup,
        );

        return response()->json([
            'job_key'      => $jobKey,
            'username'     => $username,
            'source'       => $data['source'],
            'on_duplicate' => $onDup,
        ], 202);
    }

    /**
     * GET /api/decks/import/bulk/{key}
     *
     * Returns the latest progress for a bulk-import job. Frontend polls
     * this every couple of seconds while the spinner is up. Returns 404
     * when the cache entry has expired.
     */
    public function bulkStatus(string $key): JsonResponse
    {
        $status = Cache::get('bulk-import:'.$key);
        if (! $status) {
            return response()->json(['message' => 'Job not found or expired'], 404);
        }
        return response()->json($status);
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
