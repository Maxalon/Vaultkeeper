<?php

namespace Tests\Feature;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP coverage for the Physical Copies tab's edit endpoint
 * (POST /api/decks/{deck}/entries/{entry}/edit-physical).
 */
class PhysicalCopyEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Deck $deck;
    private Location $deckLocation;
    private ScryfallCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Edit',
            'format'  => 'commander',
        ]);
        $this->deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $this->card = ScryfallCard::factory()->create();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function makeBoundEntry(int $quantity = 4, array $copyOverrides = []): array
    {
        $copy = CollectionEntry::factory()->create(array_merge([
            'user_id'       => $this->user->id,
            'scryfall_id'   => $this->card->scryfall_id,
            'location_id'   => $this->deckLocation->id,
            'quantity'      => $quantity,
            'condition'     => 'NM',
            'foil'          => false,
            'review_reason' => ReviewReason::DefaultValuesApplied,
        ], $copyOverrides));
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => $quantity,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);
        return [$entry, $copy];
    }

    public function test_in_place_edit_updates_copy_and_clears_review_reason(): void
    {
        [$entry, $copy] = $this->makeBoundEntry(quantity: 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 1,
                'condition' => 'LP',
                'foil'      => true,
                'notes'     => 'signed',
            ])
            ->assertOk();

        $copy->refresh();
        $this->assertSame('LP', $copy->condition);
        $this->assertTrue((bool) $copy->foil);
        $this->assertSame('signed', $copy->notes);
        $this->assertNull($copy->review_reason);
        $this->assertSame(1, (int) $copy->quantity);
        // No siblings minted for an apply-all edit.
        $this->assertSame(1, DeckEntry::where('deck_id', $this->deck->id)->count());
        $this->assertSame(1, CollectionEntry::where('user_id', $this->user->id)->count());
    }

    public function test_split_edit_creates_sibling_ce_and_deck_entry(): void
    {
        [$entry, $copy] = $this->makeBoundEntry(quantity: 4);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 1,
                'condition' => 'LP',
                'foil'      => true,
            ])
            ->assertOk();

        $copy->refresh();
        $entry->refresh();
        $this->assertSame(3, (int) $copy->quantity);
        $this->assertSame('NM', $copy->condition, 'source CE attrs untouched');
        $this->assertFalse((bool) $copy->foil);
        $this->assertSame(3, (int) $entry->quantity);
        $this->assertSame($copy->id, $entry->physical_copy_id);

        $newEntryId = $response->json('id');
        $this->assertNotSame($entry->id, $newEntryId);
        $sibling = DeckEntry::findOrFail($newEntryId);
        $this->assertSame(1, (int) $sibling->quantity);
        $this->assertSame('main', $sibling->zone);
        $this->assertNotNull($sibling->physical_copy_id);

        $newCopy = CollectionEntry::findOrFail($sibling->physical_copy_id);
        $this->assertSame($this->deckLocation->id, $newCopy->location_id);
        $this->assertSame(1, (int) $newCopy->quantity);
        $this->assertSame('LP', $newCopy->condition);
        $this->assertTrue((bool) $newCopy->foil);
        $this->assertNull($newCopy->review_reason);
    }

    public function test_version_mismatch_returns_412(): void
    {
        [$entry, $copy] = $this->makeBoundEntry(quantity: 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 1,
                'version'   => (int) $copy->version + 99,
                'condition' => 'LP',
            ])
            ->assertStatus(412);
    }

    public function test_printing_swap_updates_both_ce_and_deck_entry(): void
    {
        // Sibling printing of the same oracle id.
        $other = ScryfallCard::factory()->create([
            'oracle_id' => $this->card->oracle_id,
            'set_code'  => 'aaa',
        ]);

        [$entry, $copy] = $this->makeBoundEntry(quantity: 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'    => 1,
                'scryfall_id' => $other->scryfall_id,
            ])
            ->assertOk();

        $entry->refresh();
        $copy->refresh();
        $this->assertSame($other->scryfall_id, $entry->scryfall_id);
        $this->assertSame($other->scryfall_id, $copy->scryfall_id);
    }

    public function test_printing_swap_to_different_oracle_is_rejected(): void
    {
        $unrelated = ScryfallCard::factory()->create(); // fresh oracle_id
        [$entry] = $this->makeBoundEntry(quantity: 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'    => 1,
                'scryfall_id' => $unrelated->scryfall_id,
            ])
            ->assertStatus(422);
    }

    public function test_unbound_entry_cannot_be_edited(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 2,
            'zone'        => 'main',
            'wanted'      => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 1,
                'condition' => 'LP',
            ])
            ->assertStatus(422);
    }

    public function test_other_users_deck_is_forbidden(): void
    {
        [$entry] = $this->makeBoundEntry(quantity: 1);
        $otherUser = User::factory()->create();
        $otherToken = auth('api')->login($otherUser);

        $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 1,
                'condition' => 'LP',
            ])
            ->assertStatus(403);
    }

    public function test_apply_to_out_of_range_is_rejected(): void
    {
        [$entry] = $this->makeBoundEntry(quantity: 2);

        $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries/{$entry->id}/edit-physical", [
                'apply_to'  => 5,
                'condition' => 'LP',
            ])
            ->assertStatus(422);
    }
}
