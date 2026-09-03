<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The HTTP surface over CentralStockService. The rules themselves are proven in
 * CentralStockTest — what these cover is the wiring: role gates, validation, idempotency, and
 * that the response carries enough back that the client needs no follow-up call.
 */
class ShowcaseStockApiTest extends TestCase
{
    use RefreshDatabase;

    private User $barista;

    private User $staff;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '081100000005')->firstOrFail();
        $this->cart = Cart::query()->where('code', '0018')->firstOrFail();
        $this->product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function cartStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id);
    }

    public function test_a_barista_sees_their_showcase_stock(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/stock')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => [['product_id', 'product_name', 'qty']]]);
    }

    public function test_brewing_raises_the_showcase_and_returns_the_new_totals(): void
    {
        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 12]],
            ])
            ->assertSuccessful();

        // The response is the refreshed showcase, so the client needs no second request.
        $row = collect($response->json('data'))->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($row);
        $this->assertSame(212, $row['qty']); // seeded 200 + 12
    }

    public function test_handing_cups_over_returns_the_assignment_allowance_and_both_stocks(): void
    {
        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 20]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.cart_code', $this->cart->code)
            ->assertJsonPath('data.staff_id', $this->staff->id)
            ->assertJsonStructure([
                'data' => [
                    'assignment_id',
                    'allowance' => ['amount_minor', 'is_edited'],
                    'cart_stock',
                    'showcase_stock',
                ],
            ]);

        $this->assertSame(50000, $response->json('data.allowance.amount_minor'));
        $this->assertSame(20, $this->cartStock());
    }

    public function test_an_overridden_allowance_is_accepted_and_flagged(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
                'allowance_amount' => 80000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.allowance.amount_minor', 80000)
            ->assertJsonPath('data.allowance.is_edited', true);
    }

    /** R12: a retried tap in a kitchen must not hand the same cups over twice. */
    public function test_replaying_the_same_idempotency_key_does_not_move_stock_twice(): void
    {
        $key = $this->key();
        $payload = [
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 7]],
        ];

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/showcase/hand-to-cart', $payload)
            ->assertCreated();

        $afterFirst = $this->cartStock();

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/showcase/hand-to-cart', $payload)
            ->assertSuccessful();

        $this->assertSame($afterFirst, $this->cartStock());
    }

    /** 422, matching what the Idempotency middleware already returns everywhere else in this API. */
    public function test_a_write_without_an_idempotency_key_is_refused(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422);
    }

    public function test_staff_cannot_reach_the_showcase_endpoints(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/showcase/stock')
            ->assertForbidden();

        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertForbidden();
    }

    public function test_handing_cups_to_a_non_staff_account_is_a_field_error(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->barista->id, // a barista, not staff
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_business_rule_failures_come_back_as_422_with_the_reason(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 100]],
            ])
            ->assertCreated();

        // Cart 0018 is now taken for today; a different staff member is a real conflict.
        $otherStaff = User::factory()->role(Role::STAFF)->create([
            'kitchen_id' => $this->barista->kitchen_id,
        ]);

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $otherStaff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422);
    }

    public function test_close_out_moves_returned_and_rejected_cups_off_the_cart(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 30]],
            ])
            ->assertCreated();

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 6]],
                'rejected' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['cart_stock', 'showcase_stock']]);

        $this->assertSame(22, $this->cartStock()); // 30 - 6 - 2
    }

    public function test_close_out_with_nothing_reported_is_a_validation_error(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', ['cart_id' => $this->cart->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returned');
    }

    public function test_the_allowance_endpoint_creates_and_returns_todays_amount(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->getJson("/api/v1/showcase/allowance/{$this->cart->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.amount_minor', 50000)
            ->assertJsonPath('data.is_edited', false);
    }

    /** The reason this endpoint exists: no cart is blocked by paperwork nobody did. */
    public function test_a_cart_with_no_assignment_today_can_still_be_given_stock(): void
    {
        $locationId = (int) StaffAssignment::query()->firstOrFail()->location_id;
        StaffAssignment::query()->delete();

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 9]],
                'location_id' => $locationId,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('staff_assignments', [
            'cart_id' => $this->cart->id,
            'user_id' => $this->staff->id,
        ]);
    }
}
