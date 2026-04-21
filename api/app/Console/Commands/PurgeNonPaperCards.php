<?php

namespace App\Console\Commands;

use App\Services\ScryfallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot operation: re-seed scryfall_cards as paper-only. Run once
 * immediately after the DB-2 migration deploys. Idempotent — subsequent
 * runs find nothing to purge because the bulk sync's paper-only filter
 * keeps the table paper-only going forward.
 *
 * Preflight: collect every scryfall_id referenced by user data
 * (collection_entries + deck_entries), query Scryfall's /cards/collection
 * for the actual `games` field, and halt if any come back non-paper.
 * That keeps the FK graph intact — we never truncate scryfall_cards while
 * user data still points at a row that won't reappear in the re-sync.
 *
 * Use --force to skip the preflight (only sensible for first-install
 * environments with no user data yet).
 */
class PurgeNonPaperCards extends Command
{
    protected $signature = 'scryfall:purge-non-paper {--force : Skip the online preflight (no user data protection)}';

    protected $description = 'Re-seed scryfall_cards as paper-only. Refuses to run if user data references non-paper cards.';

    public function handle(ScryfallService $scryfall): int
    {
        $this->info('scryfall:purge-non-paper');

        $before = (int) DB::table('scryfall_cards')->count();
        $this->line("  scryfall_cards before: {$before}");

        if (! $this->option('force')) {
            $refs = $this->collectUserRefs();
            $this->line("  preflight: {$refs['count']} distinct scryfall_ids referenced by user data");

            if ($refs['count'] > 0) {
                $this->line('  checking Scryfall for paper status (may take ~' . ceil($refs['count'] / 75) . ' requests)…');
                $blockers = $this->checkPaperStatus($scryfall, $refs['ids'], $refs['byId']);
                if (! empty($blockers)) {
                    $this->error('');
                    $this->error('Cannot purge — the following user rows point at non-paper cards:');
                    foreach ($blockers as $b) {
                        $user = $b['user_id'] === null ? 'unknown' : "user#{$b['user_id']}";
                        $this->line("  [{$b['src']}] {$user}  {$b['name']}  ({$b['scryfall_id']})");
                    }
                    $this->line('');
                    $this->error('Resolve (delete or reassign to a paper printing) then re-run.');
                    return self::FAILURE;
                }
                $this->line('  all user-referenced cards are paper ✓');
            }
        } else {
            $this->warn('  --force: skipping online preflight');
        }

        $this->line('  truncating scryfall_cards_raw + scryfall_cards…');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::statement('TRUNCATE scryfall_cards_raw');
            DB::statement('TRUNCATE scryfall_cards');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->line('  running scryfall:sync-bulk (paper-only filter active)…');
        $syncExit = $this->call('scryfall:sync-bulk');
        if ($syncExit !== self::SUCCESS) {
            $this->error('  sync-bulk failed — scryfall_cards is empty. Re-run manually.');
            return self::FAILURE;
        }

        $orphansDeleted = DB::delete(
            'DELETE FROM card_oracle_tags '
            . 'WHERE oracle_id NOT IN (SELECT oracle_id FROM scryfall_cards)'
        );
        $this->line("  orphan card_oracle_tags removed: {$orphansDeleted}");

        $after = (int) DB::table('scryfall_cards')->count();
        $this->info("Done. scryfall_cards: {$before} → {$after}");

        return self::SUCCESS;
    }

    /**
     * Gather every distinct scryfall_id referenced by user data, plus a
     * back-pointer so we can tell the user WHERE a blocker lives.
     *
     * @return array{count: int, ids: array<int, string>, byId: array<string, array<int, array{src: string, user_id: int|null, name: string}>>}
     */
    private function collectUserRefs(): array
    {
        $byId = [];

        DB::table('collection_entries')
            ->join('scryfall_cards', 'scryfall_cards.scryfall_id', '=', 'collection_entries.scryfall_id')
            ->select(
                'collection_entries.scryfall_id',
                'collection_entries.user_id',
                'scryfall_cards.name'
            )
            ->orderBy('collection_entries.id')
            ->chunk(1000, function ($rows) use (&$byId) {
                foreach ($rows as $r) {
                    $byId[$r->scryfall_id][] = [
                        'src'         => 'collection',
                        'user_id'     => $r->user_id,
                        'scryfall_id' => $r->scryfall_id,
                        'name'        => $r->name,
                    ];
                }
            });

        DB::table('deck_entries')
            ->join('decks', 'decks.id', '=', 'deck_entries.deck_id')
            ->join('scryfall_cards', 'scryfall_cards.scryfall_id', '=', 'deck_entries.scryfall_id')
            ->select(
                'deck_entries.scryfall_id',
                'decks.user_id',
                'scryfall_cards.name'
            )
            ->orderBy('deck_entries.id')
            ->chunk(1000, function ($rows) use (&$byId) {
                foreach ($rows as $r) {
                    $byId[$r->scryfall_id][] = [
                        'src'         => 'deck',
                        'user_id'     => $r->user_id,
                        'scryfall_id' => $r->scryfall_id,
                        'name'        => $r->name,
                    ];
                }
            });

        $ids = array_keys($byId);
        return ['count' => count($ids), 'ids' => $ids, 'byId' => $byId];
    }

    /**
     * Batch-fetch each scryfall_id from Scryfall's collection endpoint and
     * return the user-row back-pointers for any card whose `games` array
     * doesn't include 'paper'.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, array<int, array{src: string, user_id: int|null, scryfall_id: string, name: string}>>  $byId
     * @return array<int, array{src: string, user_id: int|null, scryfall_id: string, name: string}>
     */
    private function checkPaperStatus(ScryfallService $scryfall, array $ids, array $byId): array
    {
        $results = $scryfall->fetchCardCollection($ids);
        $blockers = [];

        foreach ($ids as $id) {
            $card = $results[$id] ?? null;
            if ($card === null) {
                // Scryfall didn't return it — likely a migration-deleted
                // card. Treat as blocker so the operator can investigate.
                foreach ($byId[$id] ?? [] as $ref) {
                    $blockers[] = $ref + ['reason' => 'not_found'];
                }
                continue;
            }
            $games = (array) ($card['games'] ?? []);
            if (! in_array('paper', $games, true)) {
                foreach ($byId[$id] ?? [] as $ref) {
                    $blockers[] = $ref;
                }
            }
        }

        return $blockers;
    }
}
