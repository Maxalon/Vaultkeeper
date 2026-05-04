<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\PendingRelocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingRelocationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Build a deck whose entry is bound to a CE in the deck-location, then
     * shrink the entry so the observer pushes the CE to pending. Returns
     * the now-pending CollectionEntry plus the source deck.
     *
     * @return array{deck: Deck, copy: CollectionEntry}
     */
    private function buildPending(string $deckName = 'Selesnya'): array
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => $deckName,
            'format'  => 'commander',
        ]);
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLoc->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $deck->id,
            'scryfall_id'      => $card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);
        // Destroying the entry routes its bound copy to pending via the
        // DeckEntryObserver — that's the same path the inline shrink
        // pickers will use.
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        return ['deck' => $deck, 'copy' => $copy->fresh()];
    }

    public function test_index_lists_pending_copies_with_source_deck_label(): void
    {
        ['deck' => $deck, 'copy' => $copy] = $this->buildPending('Selesnya Tokens');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/pending-relocations')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($copy->id, $rows[0]['id']);
        $this->assertSame($deck->id, $rows[0]['source_deck']['deck_id']);
        $this->assertSame('Selesnya Tokens', $rows[0]['source_deck']['deck_name']);
        $this->assertFalse($rows[0]['source_deck']['deleted']);
    }

    public function test_index_returns_empty_when_user_has_no_pending_bucket(): void
    {
        // Fresh user, never shrunk a deck → no pending bucket exists at
        // all. The endpoint should not 404 or auto-create the bucket.
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/pending-relocations')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
    }

    public function test_index_filters_by_deck_id(): void
    {
        ['deck' => $deckA, 'copy' => $copyA] = $this->buildPending('Deck A');
        ['copy' => $copyB] = $this->buildPending('Deck B');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/pending-relocations?deck_id='.$deckA->id)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($copyA->id, $ids);
        $this->assertNotContains($copyB->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_count_returns_zero_when_bucket_missing(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/pending-relocations/count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_count_reflects_pending_size(): void
    {
        $this->buildPending();
        $this->buildPending();
        $this->withHeaders($this->headers())
            ->getJson('/api/pending-relocations/count')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_resolve_moves_to_target_and_clears_source_deck_stamp(): void
    {
        ['copy' => $copy] = $this->buildPending();
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [
                    [
                        'collection_entry_id' => $copy->id,
                        'target_location_id'  => $drawer->id,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 1, 'merged' => 0, 'discarded' => 0, 'skipped' => 0]);

        $copy->refresh();
        $this->assertSame($drawer->id, $copy->location_id);
        $this->assertNull($copy->source_deck_id);
        $this->assertNull($copy->source_deck_name_snapshot);
        $this->assertFalse($copy->source_deck_deleted);
    }

    public function test_resolve_discard_deletes_the_copy(): void
    {
        ['copy' => $copy] = $this->buildPending();

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id, 'discard' => true],
                ],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'discarded' => 1]);

        $this->assertNull(CollectionEntry::find($copy->id));
    }

    public function test_resolve_skips_assignments_with_no_action(): void
    {
        ['copy' => $copy] = $this->buildPending();

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id], // no target, no discard
                ],
            ])
            ->assertOk()
            ->assertJson(['skipped' => 1]);

        // Copy stays put.
        $copy->refresh();
        $bucket = Location::where('user_id', $this->user->id)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->firstOrFail();
        $this->assertSame($bucket->id, $copy->location_id);
    }

    public function test_resolve_merges_into_existing_destination_row(): void
    {
        ['copy' => $copy] = $this->buildPending();
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        // Pre-existing CE at the destination with same printing + condition + foil.
        $existing = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $copy->scryfall_id,
            'location_id' => $drawer->id,
            'quantity'    => 4,
            'condition'   => $copy->condition,
            'foil'        => (bool) $copy->foil,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $copy->id,
                    'target_location_id'  => $drawer->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'merged' => 1]);

        $this->assertNull(CollectionEntry::find($copy->id), 'pending row should be deleted post-merge');
        $existing->refresh();
        $this->assertSame(4 + (int) $copy->quantity, (int) $existing->quantity);
    }

    public function test_resolve_rejects_auto_managed_target_locations(): void
    {
        ['deck' => $deck, 'copy' => $copy] = $this->buildPending();
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $copy->id,
                    'target_location_id'  => $deckLoc->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'skipped' => 1]);

        // Copy stays in the pending bucket, untouched.
        $copy->refresh();
        $bucket = Location::where('user_id', $this->user->id)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->firstOrFail();
        $this->assertSame($bucket->id, $copy->location_id);
    }

    public function test_resolve_silently_skips_other_users_copies(): void
    {
        $otherUser = User::factory()->create();
        // Build pending in the other user's account by reaching into the
        // service directly — controller routes are auth-scoped, so we
        // can't go through them.
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $otherUser->id,
            'name'    => 'Other',
            'format'  => 'commander',
        ]);
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();
        $foreignCopy = CollectionEntry::factory()->create([
            'user_id'     => $otherUser->id,
            'scryfall_id' => $card->scryfall_id,
            'location_id' => $deckLoc->id,
        ]);
        app(PendingRelocationService::class)->moveCopyToPending($foreignCopy->fresh(), $deck);

        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $foreignCopy->id,
                    'target_location_id'  => $drawer->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['skipped' => 1]);

        // Foreign copy untouched.
        $foreignCopy->refresh();
        $foreignPending = Location::where('user_id', $otherUser->id)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->firstOrFail();
        $this->assertSame($foreignPending->id, $foreignCopy->location_id);
    }
}
