<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Models\Cart;
use App\Models\DailyAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `GET /badges` (docs/04 §Notifications, docs/02 §8). Keys irrelevant to the role stay 0. */
class BadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_staff_badge_defaults_to_all_zero_counts_when_nothing_is_pending(): void
    {
        $staff = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/badges')
            ->assertStatus(200)
            ->assertJson(['data' => [
                'pendingApprovals' => 0,
                'incomingRequests' => 0,
                'readyToPick' => 0,
                'myRequests' => 0,
            ]]);
    }

    public function test_finance_badge_counts_pending_finance_allocations_and_leaves_other_keys_zero(): void
    {
        $finance = User::query()->where('phone_e164', '+6281100000002')->firstOrFail();
        $barista = User::query()->where('phone_e164', '+6281100000003')->firstOrFail();
        $staff = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();
        $cart = Cart::query()->where('code', '0018')->firstOrFail();

        DailyAllocation::query()->create([
            'operating_date' => now()->toDateString(),
            'cart_id' => $cart->id,
            'staff_id' => $staff->id,
            'kitchen_id' => $cart->kitchen_id,
            'barista_id' => $barista->id,
            'location_id' => $staff->staffAssignments()->first()->location_id,
            'status' => AllocationStatus::PENDING_FINANCE,
            'is_correction' => false,
            'over_target_pct' => 40,
        ]);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/badges')
            ->assertStatus(200)
            ->assertJsonPath('data.pendingApprovals', 1)
            ->assertJsonPath('data.incomingRequests', 0)
            ->assertJsonPath('data.readyToPick', 0)
            ->assertJsonPath('data.myRequests', 0);
    }
}
