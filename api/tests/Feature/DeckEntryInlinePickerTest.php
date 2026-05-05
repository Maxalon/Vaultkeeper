<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level coverage for the inline-picker hooks the right-click menu
 * targets. These confirm `mode` / `discard` body params route through
 * DeckEntryActionService instead of replaying the observer's defaults.
 */
class DeckEntryInlinePickerTest extends TestCase
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
            'name'    => 'Inline',
            'format'  => 'commander',
        ]);
        $this->deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();
        $this->card = ScryfallCard::factory()->create();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_store_with_create_new_copy_creates_ce_in_deck_location(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/decks/{$this->deck->id}/entries", [
                'scryfall_id' => $this->card->scryfall_id,
                'zone'        => 'main',
                'quantity'    => 2,
                'mode'        => 'create_new_copy',
            ])
            ->assertCreated();

        $entryId = $response->json('id');
        $entry   = DeckEntry::findOrFail($entryId);
        $this->assertNotNull($entry->physical_copy_id);

        $copy = CollectionEntry::find($entry->physical_copy_id);
        $this->assertSame($this->deckLocation->id, $copy->location_id);
        $this->assertSame(2, (int) $copy->quantity);
        $this->assertFalse((bool) $copy->needs_review, 'user-confirmed CE should not be flagged for review');
    }

    public function test_update_with_create_new_copy_binds_unbound_slot(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 3,
            'zone'        => 'main',
            'wanted'      => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'mode' => 'create_new_copy',
            ])
            ->assertOk();

        $entry->refresh();
        $this->assertNotNull($entry->physical_copy_id);
        $this->assertNull($entry->wanted, 'binding clears the wanted flag');
        $copy = CollectionEntry::find($entry->physical_copy_id);
        $this->assertSame(3, (int) $copy->quantity, 'new CE matches the slot quantity');
        $this->assertSame($this->deckLocation->id, $copy->location_id);
    }

    public function test_update_with_create_new_copy_grows_bound_slot_by_delta(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
            'quantity'    => 2,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 2,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'quantity' => 4,
                'mode'     => 'create_new_copy',
            ])
            ->assertOk();

        $entry->refresh();
        $this->assertSame(4, (int) $entry->quantity);
        $this->assertSame(4, (int) $copy->fresh()->quantity);
    }

    public function test_update_create_new_copy_rejects_quantity_decrease(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 4,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'quantity' => 2,
                'mode'     => 'create_new_copy',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_update_with_discard_shrinks_without_pending_queue(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
            'quantity'    => 4,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 4,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'quantity' => 3,
                'discard'  => true,
            ])
            ->assertOk();

        $entry->refresh();
        $this->assertSame(3, (int) $entry->quantity);
        $this->assertSame(3, (int) $copy->fresh()->quantity);
        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
    }

    public function test_update_discard_requires_strict_quantity_decrease(): void
    {
        $entry = DeckEntry::create([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $this->card->scryfall_id,
            'quantity'    => 4,
            'zone'        => 'main',
        ]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/decks/{$this->deck->id}/entries/{$entry->id}", [
                'quantity' => 4,
                'discard'  => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discard']);
    }

    public function test_destroy_with_discard_deletes_bound_ce_outright(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$this->deck->id}/entries/{$entry->id}?discard=true")
            ->assertNoContent();

        $this->assertNull(DeckEntry::find($entry->id));
        $this->assertNull(CollectionEntry::find($copy->id));
        $this->assertDatabaseMissing('locations', [
            'user_id' => $this->user->id,
            'role'    => Location::ROLE_PENDING_RELOCATION,
        ]);
    }

    public function test_destroy_default_path_still_queues_to_pending(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'scryfall_id' => $this->card->scryfall_id,
            'location_id' => $this->deckLocation->id,
        ]);
        $entry = DeckEntry::create([
            'deck_id'          => $this->deck->id,
            'scryfall_id'      => $this->card->scryfall_id,
            'quantity'         => 1,
            'zone'             => 'main',
            'physical_copy_id' => $copy->id,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/decks/{$this->deck->id}/entries/{$entry->id}")
            ->assertNoContent();

        $copy->refresh();
        $pending = Location::where('user_id', $this->user->id)
            ->where('role', Location::ROLE_PENDING_RELOCATION)
            ->firstOrFail();
        $this->assertSame($pending->id, $copy->location_id);
    }
}
