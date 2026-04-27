<?php

namespace App\Jobs;

use App\Models\LocationGroup;
use App\Models\User;
use App\Services\DeckImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Imports every deck from an Archidekt or Moxfield user profile, mirroring
 * the source's folder structure as Vaultkeeper LocationGroups. Progress is
 * surfaced to the frontend through the cache (key=`bulk-import:{key}`); the
 * UI polls that key while showing a spinner.
 */
class BulkImportUserDecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    public function __construct(
        public int $userId,
        public string $source,
        public string $username,
        public string $jobKey,
        public string $onDuplicate = 'skip', // 'skip' | 'update'
    ) {}

    public function handle(DeckImportService $importer): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $this->writeStatus([
                'state'   => 'failed',
                'message' => 'User not found',
            ]);
            return;
        }

        try {
            $decks = $this->source === 'moxfield'
                ? $importer->listMoxfieldUserDecks($this->username)
                : $importer->listArchidektUserDecks($this->username);
        } catch (\Throwable $e) {
            $this->writeStatus([
                'state'   => 'failed',
                'message' => $e->getMessage(),
            ]);
            return;
        }

        $total = count($decks);
        if ($total === 0) {
            $this->writeStatus([
                'state'    => 'done',
                'total'    => 0,
                'imported' => 0,
                'failed'   => 0,
                'message'  => "No public decks found for {$this->username}.",
            ]);
            return;
        }

        $this->writeStatus([
            'state'    => 'running',
            'total'    => $total,
            'imported' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'message'  => "Found {$total} decks. Importing…",
        ]);

        // Cache LocationGroups by their flattened folder path for the run so
        // we don't hit the DB for every deck.
        $groupCache = [];
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;
        $warnings = [];

        foreach ($decks as $deck) {
            // Skip-mode short-circuit: if we already imported this source
            // deck, don't even hit the upstream API again.
            if ($this->onDuplicate === 'skip') {
                [$src, $sid] = $importer->parseSource($deck['url']);
                if ($importer->findExistingBySource($user->id, $src, $sid)) {
                    $skipped++;
                    $this->emitProgress($total, $imported, $updated, $skipped, $failed);
                    continue;
                }
            }

            $groupId = null;
            $folderPath = $deck['folder_path'] ?? null;
            if ($folderPath !== null) {
                $groupId = $groupCache[$folderPath] ?? null;
                if ($groupId === null) {
                    $groupId = $this->resolveGroupId($user->id, $folderPath);
                    $groupCache[$folderPath] = $groupId;
                }
            }

            try {
                // Update mode: importFromUrl with mode=update overwrites a
                // matching deck if one exists, otherwise creates a new one.
                // We pre-check existence to know which counter to bump.
                $isUpdate = false;
                if ($this->onDuplicate === 'update') {
                    [$src, $sid] = $importer->parseSource($deck['url']);
                    $isUpdate = (bool) $importer->findExistingBySource($user->id, $src, $sid);
                }
                $mode = $isUpdate ? 'update' : 'create';
                $importer->importFromUrl($user, $deck['url'], $groupId, $mode);
                $isUpdate ? $updated++ : $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $warnings[] = "{$deck['name']}: {$e->getMessage()}";
                Log::warning('Bulk import deck failed', [
                    'user_id' => $user->id,
                    'deck'    => $deck,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Light throttle to be polite to upstream APIs.
            usleep(750_000);

            $this->emitProgress($total, $imported, $updated, $skipped, $failed);
        }

        $message = "Imported {$imported} of {$total} decks";
        if ($updated > 0) $message .= " ({$updated} updated)";
        if ($skipped > 0) $message .= " ({$skipped} already in your library)";
        if ($failed > 0)  $message .= " ({$failed} failed)";

        $this->writeStatus([
            'state'    => 'done',
            'total'    => $total,
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'warnings' => array_slice($warnings, 0, 25),
            'message'  => $message.'.',
        ]);
    }

    private function emitProgress(int $total, int $imported, int $updated, int $skipped, int $failed): void
    {
        $done = $imported + $updated + $skipped + $failed;
        $this->writeStatus([
            'state'    => 'running',
            'total'    => $total,
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'message'  => "Processed {$done} of {$total}…",
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->writeStatus([
            'state'   => 'failed',
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * Look up or create a LocationGroup for a flattened folder path. We
     * match case-insensitively on `name` so re-imports drop into the same
     * group instead of creating a duplicate.
     */
    private function resolveGroupId(int $userId, string $folderPath): int
    {
        $name = mb_substr($folderPath, 0, 100);

        $existing = LocationGroup::where('user_id', $userId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing) return $existing->id;

        return LocationGroup::create([
            'user_id'    => $userId,
            'name'       => $name,
            'sort_order' => LocationGroup::nextTopLevelSortOrder($userId),
        ])->id;
    }

    private function writeStatus(array $data): void
    {
        // Hold for an hour — long enough for the frontend to finish polling
        // even if the user closes/reopens the tab.
        Cache::put($this->cacheKey(), $data + ['updated_at' => now()->toIso8601String()], 3600);
    }

    private function cacheKey(): string
    {
        return 'bulk-import:'.$this->jobKey;
    }
}
