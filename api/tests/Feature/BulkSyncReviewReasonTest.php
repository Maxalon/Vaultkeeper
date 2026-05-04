<?php

namespace Tests\Feature;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\BulkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BulkSyncService::markDeleted is called when Scryfall's bulk file
 * declares a card was deleted (no replacement). Every CE pointing at
 * the deleted scryfall_id should land on the review queue with reason
 * `card_data_changed`.
 *
 * markDeleted is private; we exercise it via reflection because the
 * full migration pipeline pulls from a network service we don't want
 * to mock.
 */
class BulkSyncReviewReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_deleted_flags_collection_entries_with_card_data_changed(): void
    {
        $user = User::factory()->create();
        $drawer = Location::factory()->create(['user_id' => $user->id]);

        $oldCard = ScryfallCard::factory()->create(['name' => 'Doomed Printing']);
        $copy = CollectionEntry::factory()->create([
            'user_id'     => $user->id,
            'scryfall_id' => $oldCard->scryfall_id,
            'location_id' => $drawer->id,
        ]);

        $bulk = app(BulkSyncService::class);
        $method = (new \ReflectionClass($bulk))->getMethod('markDeleted');
        $method->setAccessible(true);
        $method->invoke($bulk, $oldCard->scryfall_id);

        $copy->refresh();
        $this->assertSame(ReviewReason::CardDataChanged, $copy->review_reason);
        // Location and quantity unchanged — the user gets to decide.
        $this->assertSame($drawer->id, $copy->location_id);
    }
}
