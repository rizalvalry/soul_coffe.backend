<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/02 §2.1/§2.2, R15. Proves role/ownership isolation by rejection (403), never by a
 * hidden UI element — the definition-of-done item at docs/02 §15.1.
 *
 * The spec names "a STAFF token on a FINANCE endpoint" as the representative case; this
 * developer's scope owns no Finance-only endpoint (approval of an over-target allocation has
 * no dedicated route in routes/api.php), so the equivalent barista-only endpoints
 * (`allocations/today`, `POST /allocations`) stand in for the same enforcement mechanism —
 * see the developer handoff notes.
 */
class RoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_staff_token_is_rejected_creating_an_allocation(): void
    {
        $staff = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();
        $cart = Cart::query()->where('code', '0018')->firstOrFail();

        $response = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/allocations', [
            'operating_date' => now()->toDateString(),
            'cart_id' => $cart->id,
            'staff_id' => $staff->id,
            'location_id' => $staff->staffAssignments()->first()->location_id,
            'lines' => [['product_id' => 1, 'qty_issued' => 1]],
        ], ['Idempotency-Key' => 'role-isolation-store']);

        $response->assertStatus(403);
    }

    public function test_staff_token_is_rejected_on_the_barista_allocation_worksheet(): void
    {
        $staff = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/allocations/today')
            ->assertStatus(403);
    }

    public function test_barista_token_is_rejected_on_the_staff_only_cart_stock_endpoint(): void
    {
        $barista = User::query()->where('phone_e164', '+6281100000003')->firstOrFail();

        $this->actingAs($barista, 'sanctum')
            ->getJson('/api/v1/me/stock')
            ->assertStatus(403);
    }

    public function test_r15_barista_products_response_omits_cost_and_sell_price(): void
    {
        $barista = User::query()->where('phone_e164', '+6281100000003')->firstOrFail();

        $response = $this->actingAs($barista, 'sanctum')->getJson('/api/v1/products');

        $response->assertStatus(200);

        $first = $response->json('data.0');
        $this->assertArrayNotHasKey('cost_price', $first);
        $this->assertArrayNotHasKey('sell_price', $first);
    }

    public function test_finance_products_response_includes_cost_and_sell_price(): void
    {
        $finance = User::query()->where('phone_e164', '+6281100000002')->firstOrFail();

        $response = $this->actingAs($finance, 'sanctum')->getJson('/api/v1/products');

        $response->assertStatus(200);

        $first = $response->json('data.0');
        $this->assertArrayHasKey('cost_price', $first);
        $this->assertArrayHasKey('sell_price', $first);
    }

    public function test_mark_read_on_someone_elses_notification_is_forbidden(): void
    {
        $owner = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();
        $intruder = User::query()->where('phone_e164', '+6281100000003')->firstOrFail();

        $notification = AppNotification::query()->create([
            'user_id' => $owner->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'Test',
            'payload_json' => ['title' => 'x', 'body' => 'y'],
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->assertNull($notification->fresh()->read_at);
    }
}
