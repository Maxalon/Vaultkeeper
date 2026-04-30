<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Services\PendingRelocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingRelocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_location_creates_singleton_pending_row(): void
    {
        $user = User::factory()->create();

        $location = app(PendingRelocationService::class)->ensureLocation($user);

        $this->assertEquals($user->id, $location->user_id);
        $this->assertEquals(Location::ROLE_PENDING_RELOCATION, $location->role);
    }

    public function test_ensure_location_is_idempotent_per_user(): void
    {
        $user = User::factory()->create();
        $service = app(PendingRelocationService::class);

        $first  = $service->ensureLocation($user);
        $second = $service->ensureLocation($user);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(
            1,
            Location::where('user_id', $user->id)
                ->where('role', Location::ROLE_PENDING_RELOCATION)
                ->count(),
        );
    }

    public function test_each_user_gets_their_own_pending_location(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $service = app(PendingRelocationService::class);

        $aliceLoc = $service->ensureLocation($alice);
        $bobLoc   = $service->ensureLocation($bob);

        $this->assertNotEquals($aliceLoc->id, $bobLoc->id);
    }
}
