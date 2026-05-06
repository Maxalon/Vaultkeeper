<?php

namespace Tests\Feature;

use App\Enums\ReviewReason;
use App\Models\CollectionEntry;
use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use App\Services\AssembleIntent;
use App\Services\DeckAssemblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeckAssemblyServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Deck $deck;
    private Location $deckLocation;
    private ScryfallCard $bolt;
    private ScryfallCard $solRing;
    private ScryfallCard $atraxa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name'    => 'Atraxa Build',
            'format'  => 'commander',
        ]);
        $this->deckLocation = Location::where('deck_id', $this->deck->id)->firstOrFail();

        $this->bolt    = ScryfallCard::factory()->create(['name' => 'Lightning Bolt']);
        $this->solRing = ScryfallCard::factory()->create(['name' => 'Sol Ring']);
        $this->atraxa  = ScryfallCard::factory()->create(['name' => "Atraxa, Praetors' Voice"]);
    }

    private function service(): DeckAssemblyService
    {
        return app(DeckAssemblyService::class);
    }

    private function entry(string $scryfallId, string $zone, int $qty, array $extra = []): DeckEntry
    {
        return DeckEntry::create(array_merge([
            'deck_id'     => $this->deck->id,
            'scryfall_id' => $scryfallId,
            'quantity'    => $qty,
            'zone'        => $zone,
        ], $extra));
    }

    public function test_assemble_all_creates_deck_location_ces_and_links_every_slot(): void
    {
        $bolt    = $this->entry($this->bolt->scryfall_id,    'main', 4);
        $solRing = $this->entry($this->solRing->scryfall_id, 'main', 1);
        $sideBolt = $this->entry($this->bolt->scryfall_id,   'side', 2);

        $result = $this->service()->assemble($this->deck, new AssembleIntent(all: true));

        $this->assertSame(3, $result['created_ces']);
        $this->assertSame(0, $result['slots_split']);
        $this->assertSame(0, $result['slots_marked_wanted']);

        // Each entry now has a CE in the deck-location with review_reason
        // set to DefaultValuesApplied (the user can accept defaults or
        // correct them on the /review surface).
        foreach ([$bolt, $solRing, $sideBolt] as $e) {
            $e->refresh();
            $this->assertNotNull($e->physical_copy_id, "entry {$e->id} should be bound");
            $ce = CollectionEntry::find($e->physical_copy_id);
            $this->assertSame($this->deckLocation->id, $ce->location_id);
            $this->assertSame((int) $e->quantity, (int) $ce->quantity);
            $this->assertSame(ReviewReason::DefaultValuesApplied, $ce->review_reason);
            $this->assertSame('NM', $ce->condition);
            $this->assertFalse((bool) $ce->foil);
            $this->assertNull($e->wanted);
        }
    }

    public function test_assemble_with_section_filter_only_binds_selected_zones(): void
    {
        $main = $this->entry($this->bolt->scryfall_id,    'main', 1);
        $side = $this->entry($this->bolt->scryfall_id,    'side', 1);
        $mb   = $this->entry($this->solRing->scryfall_id, 'maybe', 1);

        $intent = new AssembleIntent(all: false, sections: ['main']);
        $this->service()->assemble($this->deck, $intent);

        $main->refresh(); $side->refresh(); $mb->refresh();
        $this->assertNotNull($main->physical_copy_id);
        $this->assertNull($side->physical_copy_id);
        $this->assertNull($mb->physical_copy_id);
        // Side / maybe stay unbound and untouched (no wanted set since
        // they weren't part of the assemble at all).
        $this->assertNull($side->wanted);
        $this->assertNull($mb->wanted);
    }

    public function test_partial_exclude_splits_entry_into_bound_and_wanted_siblings(): void
    {
        $bolt = $this->entry($this->bolt->scryfall_id, 'main', 4);

        $intent = new AssembleIntent(
            all: true,
            excludes: [['scryfall_id' => $this->bolt->scryfall_id, 'zone' => 'main', 'qty' => 2]],
        );
        $result = $this->service()->assemble($this->deck, $intent);

        $this->assertSame(1, $result['created_ces']);
        $this->assertSame(1, $result['slots_split']);

        $bolt->refresh();
        $this->assertSame(2, (int) $bolt->quantity, 'shrunk to bound qty');
        $this->assertNotNull($bolt->physical_copy_id);
        $this->assertNull($bolt->wanted);

        $boundCe = CollectionEntry::find($bolt->physical_copy_id);
        $this->assertSame(2, (int) $boundCe->quantity);
        $this->assertSame($this->deckLocation->id, $boundCe->location_id);

        // Sibling row representing the missing 2 copies.
        $sibling = DeckEntry::query()
            ->where('deck_id', $this->deck->id)
            ->where('scryfall_id', $this->bolt->scryfall_id)
            ->where('zone', 'main')
            ->where('id', '!=', $bolt->id)
            ->firstOrFail();
        $this->assertSame(2, (int) $sibling->quantity);
        $this->assertNull($sibling->physical_copy_id);
        $this->assertSame('main', $sibling->wanted);
    }

    public function test_full_exclude_marks_slot_wanted_without_creating_a_ce(): void
    {
        $bolt = $this->entry($this->bolt->scryfall_id, 'main', 4);

        $intent = new AssembleIntent(
            all: true,
            excludes: [['scryfall_id' => $this->bolt->scryfall_id, 'zone' => 'main', 'qty' => 4]],
        );
        $result = $this->service()->assemble($this->deck, $intent);

        $this->assertSame(0, $result['created_ces']);
        $this->assertSame(0, $result['slots_split']);
        $this->assertSame(1, $result['slots_marked_wanted']);

        $bolt->refresh();
        $this->assertSame(4, (int) $bolt->quantity, 'unchanged on full exclude');
        $this->assertNull($bolt->physical_copy_id);
        $this->assertSame('main', $bolt->wanted);

        $this->assertSame(
            0,
            CollectionEntry::where('location_id', $this->deckLocation->id)->count(),
            'no CEs should have been created'
        );
    }

    public function test_assemble_is_additive_and_skips_already_bound_slots(): void
    {
        $bolt    = $this->entry($this->bolt->scryfall_id,    'main', 4);
        $solRing = $this->entry($this->solRing->scryfall_id, 'main', 1);

        $this->service()->assemble($this->deck, new AssembleIntent(all: true));
        $boltAfterFirst = $bolt->fresh();
        $boundCeId = $boltAfterFirst->physical_copy_id;

        // Mutate the bound CE so we can detect any silent reset on re-run.
        CollectionEntry::find($boundCeId)->update(['quantity' => 99, 'review_reason' => null]);

        $result = $this->service()->assemble($this->deck, new AssembleIntent(all: true));

        $this->assertSame(0, $result['created_ces']);
        $this->assertSame(0, $result['slots_split']);
        $this->assertSame(0, $result['slots_marked_wanted']);
        $this->assertSame($boundCeId, $bolt->fresh()->physical_copy_id);
        $this->assertSame(99, (int) CollectionEntry::find($boundCeId)->quantity, 'bound CE untouched on re-assemble');
    }

    public function test_partial_exclude_on_commander_throws(): void
    {
        $this->deck->update(['commander_1_scryfall_id' => $this->atraxa->scryfall_id]);
        $cmdr = $this->entry($this->atraxa->scryfall_id, 'main', 1, ['is_commander' => true]);

        // Synthetic payload — a UI-faithful payload couldn't construct
        // this case (the modal hides the count picker for commanders),
        // but the service must still reject it server-side.
        $intent = new AssembleIntent(
            all: true,
            excludes: [['scryfall_id' => $this->atraxa->scryfall_id, 'zone' => 'main', 'qty' => 1]],
        );

        // Full exclude (qty == quantity) goes through the wanted branch
        // and is fine. Construct a 2-qty commander to force the partial
        // path. Quantity > 1 isn't legal for a commander either, but we
        // need to hit the guard regardless of how we got there.
        $cmdr->update(['quantity' => 2]);
        $intent2 = new AssembleIntent(
            all: true,
            excludes: [['scryfall_id' => $this->atraxa->scryfall_id, 'zone' => 'main', 'qty' => 1]],
        );

        $this->expectException(\RuntimeException::class);
        $this->service()->assemble($this->deck, $intent2);
    }

    public function test_unassemble_marks_every_copy_for_review_uniformly(): void
    {
        $bolt    = $this->entry($this->bolt->scryfall_id,    'main', 4);
        $solRing = $this->entry($this->solRing->scryfall_id, 'main', 1);
        $this->service()->assemble($this->deck, new AssembleIntent(all: true));

        $boltCopy    = CollectionEntry::find($bolt->fresh()->physical_copy_id);
        $solRingCopy = CollectionEntry::find($solRing->fresh()->physical_copy_id);

        // Simulate the user editing one of the copies (e.g. condition).
        // Pre-refactor this would have routed it to "moved_to_pending"
        // while the untouched one was deleted; post-refactor both go
        // through the review queue uniformly.
        $solRingCopy->update(['condition' => 'LP', 'review_reason' => null]);

        $result = $this->service()->unassemble($this->deck);

        $this->assertSame(2, $result['marked_for_review']);
        $this->assertArrayNotHasKey('deleted', $result, 'unassemble should not delete CEs anymore');
        $this->assertArrayNotHasKey('moved_to_pending', $result);

        $this->assertNull($bolt->fresh()->physical_copy_id);
        $this->assertNull($solRing->fresh()->physical_copy_id);

        foreach ([$boltCopy, $solRingCopy] as $copy) {
            $copy->refresh();
            $this->assertNotNull(CollectionEntry::find($copy->id), 'CE preserved through unassemble');
            $this->assertNull($copy->location_id, 'no_location route — location cleared');
            $this->assertSame(ReviewReason::NoLocation, $copy->review_reason);
            $this->assertSame($this->deck->id, $copy->source_deck_id);
            $this->assertSame('Atraxa Build', $copy->source_deck_name_snapshot);
        }
    }
}
