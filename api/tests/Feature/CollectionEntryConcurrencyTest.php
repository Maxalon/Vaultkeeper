<?php

namespace Tests\Feature;

use App\Models\CollectionEntry;
use App\Models\Location;
use App\Models\ScryfallCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Optimistic-locking + lockForUpdate guards on CE mutations. The
 * `version` field is the SPA-facing safety net (412 on stale data),
 * while the per-row lock prevents lost-update interleavings between
 * concurrent transactions inside the API.
 */
class CollectionEntryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_version_increments_on_save(): void
    {
        $copy = CollectionEntry::factory()->create([
            'user_id' => $this->user->id,
        ]);
        $this->assertSame(1, (int) $copy->version, 'first save bumps from default 0 to 1');

        $copy->update(['notes' => 'first edit']);
        $this->assertSame(2, (int) $copy->fresh()->version);

        $copy->update(['notes' => 'second edit']);
        $this->assertSame(3, (int) $copy->fresh()->version);
    }

    public function test_update_with_matching_version_succeeds(): void
    {
        $copy = CollectionEntry::factory()->create(['user_id' => $this->user->id]);
        $version = (int) $copy->version;

        $this->withHeaders($this->headers())
            ->patchJson("/api/collection/{$copy->id}", [
                'notes'   => 'updated',
                'version' => $version,
            ])
            ->assertOk()
            ->assertJsonPath('version', $version + 1);

        $this->assertSame('updated', $copy->fresh()->notes);
    }

    public function test_update_with_stale_version_returns_412(): void
    {
        $copy = CollectionEntry::factory()->create(['user_id' => $this->user->id]);
        $staleVersion = (int) $copy->version;

        // Simulate someone else's write landing first.
        $copy->update(['notes' => 'someone else edited']);

        $this->withHeaders($this->headers())
            ->patchJson("/api/collection/{$copy->id}", [
                'notes'   => 'my stale edit',
                'version' => $staleVersion,
            ])
            ->assertStatus(412);

        // Loser's edit must NOT have landed.
        $this->assertSame('someone else edited', $copy->fresh()->notes);
    }

    public function test_update_without_version_skips_the_check_for_legacy_clients(): void
    {
        $copy = CollectionEntry::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->headers())
            ->patchJson("/api/collection/{$copy->id}", ['notes' => 'no version field'])
            ->assertOk();

        $this->assertSame('no version field', $copy->fresh()->notes);
    }

    public function test_destroy_with_stale_version_returns_412(): void
    {
        $copy = CollectionEntry::factory()->create(['user_id' => $this->user->id]);
        $staleVersion = (int) $copy->version;

        $copy->update(['notes' => 'concurrent edit']);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/collection/{$copy->id}?version={$staleVersion}")
            ->assertStatus(412);

        $this->assertNotNull(CollectionEntry::find($copy->id), 'CE must survive a stale-version delete');
    }

    public function test_collection_response_carries_version_field(): void
    {
        $loc = Location::factory()->create(['user_id' => $this->user->id]);
        CollectionEntry::factory()->create([
            'user_id'     => $this->user->id,
            'location_id' => $loc->id,
        ]);
        ScryfallCard::factory()->create();

        $payload = $this->withHeaders($this->headers())
            ->getJson('/api/collection?location_id='.$loc->id)
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($payload);
        $this->assertArrayHasKey('version', $payload[0]);
        $this->assertSame(1, $payload[0]['version'], 'version exposed in list payload');
    }
}
