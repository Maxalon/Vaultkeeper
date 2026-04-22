<?php

namespace Tests\Unit;

use App\Models\ScryfallCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScryfallCardProducedManaTest extends TestCase
{
    use RefreshDatabase;

    public function test_produced_mana_round_trips_as_array(): void
    {
        $card = ScryfallCard::factory()->create([
            'produced_mana' => ['W', 'U', 'B', 'R', 'G'],
        ]);

        $fresh = ScryfallCard::find($card->id);

        $this->assertIsArray($fresh->produced_mana);
        $this->assertSame(['W', 'U', 'B', 'R', 'G'], $fresh->produced_mana);
    }

    public function test_produced_mana_null_for_non_producers(): void
    {
        $card = ScryfallCard::factory()->create([
            'produced_mana' => null,
        ]);

        $fresh = ScryfallCard::find($card->id);

        $this->assertNull($fresh->produced_mana);
    }
}
