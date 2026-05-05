<?php

namespace Tests\Feature;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\ReviewQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
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
     * shrink the entry so the observer routes the CE to review with
     * reason `no_location`. Returns the now-flagged CollectionEntry plus
     * the source deck.
     *
     * @return array{deck: Deck, copy: CollectionEntry}
     */
    private function buildReviewable(string $deckName = 'Selesnya'): array
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
        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        return ['deck' => $deck, 'copy' => $copy->fresh()];
    }

    /**
     * Build a deck-location CE with reason DefaultValuesApplied (without
     * unassembling). Used by the accept_defaults tests.
     */
    private function buildDefaultValuesCe(): array
    {
        $card = ScryfallCard::factory()->create();
        $deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Defaults',
            'format'  => 'commander',
        ]);
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();
        $copy = CollectionEntry::factory()->create([
            'user_id'       => $this->user->id,
            'scryfall_id'   => $card->scryfall_id,
            'location_id'   => $deckLoc->id,
            'review_reason' => ReviewReason::DefaultValuesApplied,
        ]);
        return ['deck' => $deck, 'copy' => $copy];
    }

    public function test_index_lists_review_copies_with_source_deck_label(): void
    {
        ['deck' => $deck, 'copy' => $copy] = $this->buildReviewable('Selesnya Tokens');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/review')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($copy->id, $rows[0]['id']);
        $this->assertSame('no_location', $rows[0]['review_reason']);
        $this->assertSame($deck->id, $rows[0]['source_deck']['deck_id']);
        $this->assertSame('Selesnya Tokens', $rows[0]['source_deck']['deck_name']);
        $this->assertFalse($rows[0]['source_deck']['deleted']);
    }

    public function test_index_returns_empty_for_a_clean_account(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/review')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_index_filters_by_deck_id(): void
    {
        ['deck' => $deckA, 'copy' => $copyA] = $this->buildReviewable('Deck A');
        ['copy' => $copyB] = $this->buildReviewable('Deck B');

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/review?deck_id='.$deckA->id)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($copyA->id, $ids);
        $this->assertNotContains($copyB->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_index_filters_by_reason(): void
    {
        ['copy' => $noLocCopy] = $this->buildReviewable();
        ['copy' => $defaultsCopy] = $this->buildDefaultValuesCe();

        $rows = $this->withHeaders($this->headers())
            ->getJson('/api/review?reason=default_values_applied')
            ->assertOk()
            ->json('data');

        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($defaultsCopy->id, $ids);
        $this->assertNotContains($noLocCopy->id, $ids);
    }

    public function test_count_returns_zero_when_nothing_flagged(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/review/count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_count_reflects_review_size(): void
    {
        $this->buildReviewable();
        $this->buildReviewable();
        $this->withHeaders($this->headers())
            ->getJson('/api/review/count')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_resolve_moves_to_target_and_clears_review_reason(): void
    {
        ['copy' => $copy] = $this->buildReviewable();
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [
                    [
                        'collection_entry_id' => $copy->id,
                        'target_location_id'  => $drawer->id,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'resolved'  => 1,
                'merged'    => 0,
                'discarded' => 0,
                'accepted'  => 0,
                'skipped'   => 0,
            ]);

        $copy->refresh();
        $this->assertSame($drawer->id, $copy->location_id);
        $this->assertNull($copy->review_reason);
        $this->assertNull($copy->source_deck_id);
        $this->assertNull($copy->source_deck_name_snapshot);
        $this->assertFalse($copy->source_deck_deleted);
    }

    public function test_resolve_discard_deletes_the_copy(): void
    {
        ['copy' => $copy] = $this->buildReviewable();

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id, 'discard' => true],
                ],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'discarded' => 1]);

        $this->assertNull(CollectionEntry::find($copy->id));
    }

    public function test_resolve_accept_defaults_clears_reason_in_place(): void
    {
        ['copy' => $copy] = $this->buildDefaultValuesCe();
        $originalLocId = $copy->location_id;

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id, 'accept_defaults' => true],
                ],
            ])
            ->assertOk()
            ->assertJson(['accepted' => 1, 'resolved' => 0, 'merged' => 0]);

        $copy->refresh();
        $this->assertNull($copy->review_reason);
        $this->assertSame($originalLocId, $copy->location_id, 'accept_defaults must NOT move the row');
    }

    public function test_resolve_accept_defaults_rejected_for_other_reasons(): void
    {
        ['copy' => $copy] = $this->buildReviewable();
        // copy has review_reason = no_location, not DefaultValuesApplied.

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id, 'accept_defaults' => true],
                ],
            ])
            ->assertStatus(422);

        $copy->refresh();
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason, 'state untouched on reject');
    }

    public function test_resolve_skips_assignments_with_no_action(): void
    {
        ['copy' => $copy] = $this->buildReviewable();

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [
                    ['collection_entry_id' => $copy->id], // no target, no discard, no accept
                ],
            ])
            ->assertOk()
            ->assertJson(['skipped' => 1]);

        $copy->refresh();
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason);
    }

    public function test_resolve_merges_into_existing_destination_row(): void
    {
        ['copy' => $copy] = $this->buildReviewable();
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $existing = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $copy->scryfall_id,
            'location_id' => $drawer->id,
            'quantity'    => 4,
            'condition'   => $copy->condition,
            'foil'        => (bool) $copy->foil,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $copy->id,
                    'target_location_id'  => $drawer->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'merged' => 1]);

        $this->assertNull(CollectionEntry::find($copy->id), 'review row should be deleted post-merge');
        $existing->refresh();
        $this->assertSame(4 + (int) $copy->quantity, (int) $existing->quantity);
    }

    public function test_resolve_rejects_auto_managed_target_locations(): void
    {
        ['deck' => $deck, 'copy' => $copy] = $this->buildReviewable();
        $deckLoc = Location::where('deck_id', $deck->id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $copy->id,
                    'target_location_id'  => $deckLoc->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['resolved' => 0, 'skipped' => 1]);

        $copy->refresh();
        $this->assertSame(ReviewReason::NoLocation, $copy->review_reason, 'review state untouched');
    }

    public function test_resolve_silently_skips_other_users_copies(): void
    {
        $otherUser = User::factory()->create();
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
        app(ReviewQueueService::class)->markCopyForReview($foreignCopy->fresh(), $deck);

        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/review/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $foreignCopy->id,
                    'target_location_id'  => $drawer->id,
                ]],
            ])
            ->assertOk()
            ->assertJson(['skipped' => 1]);

        $foreignCopy->refresh();
        $this->assertNull($foreignCopy->location_id, 'foreign copy untouched');
        $this->assertSame(ReviewReason::NoLocation, $foreignCopy->review_reason);
    }

    public function test_pending_relocations_alias_routes_resolve_through_to_review(): void
    {
        ['copy' => $copy] = $this->buildReviewable();
        $drawer = Location::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->postJson('/api/pending-relocations/resolve', [
                'assignments' => [[
                    'collection_entry_id' => $copy->id,
                    'target_location_id'  => $drawer->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('resolved', 1);
    }
}
