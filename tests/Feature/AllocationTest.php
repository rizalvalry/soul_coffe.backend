<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flow A (docs/02 §5, docs/04 §Flow A). Seeded fixtures: cart 0018 has Maufu (STAFF) assigned
 * today, daily_targets = 5 cups per sellable product per cart, kitchen stock = 200 per product.
 */
class AllocationTest extends TestCase
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

        $this->barista = User::query()->where('phone_e164', '+6281100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();
        $this->cart = Cart::query()->where('code', '0018')->firstOrFail();
        $this->product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $qty, ?string $correctionReason = null): array
    {
        $data = [
            'operating_date' => now()->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'location_id' => $this->staff->staffAssignments()->first()->location_id,
            'lines' => [['product_id' => $this->product->id, 'qty_issued' => $qty]],
        ];

        if ($correctionReason !== null) {
            $data['correction_reason'] = $correctionReason;
        }

        return $data;
    }

    public function test_allocation_within_target_is_issued_and_cart_stock_increases_by_exactly_the_allocated_amount(): void
    {
        $response = $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $this->payload(5), ['Idempotency-Key' => 'alloc-within-1']);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.over_target_pct', 0);

        $ledger = app(StockLedgerService::class);

        $this->assertSame(5, $ledger->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertSame(195, $ledger->stockFor(StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id));
    }

    public function test_allocation_at_25_percent_over_target_is_pending_finance_and_stock_is_not_moved(): void
    {
        $products = Product::query()->where('is_sellable', true)->orderBy('sort_order')->take(4)->get();

        // Seeded target is 5 per product => total target 20. Requesting 25 total is +25%.
        $quantities = [6, 6, 6, 7];
        $lines = [];
        foreach ($products as $index => $product) {
            $lines[] = ['product_id' => $product->id, 'qty_issued' => $quantities[$index]];
        }

        $payload = [
            'operating_date' => now()->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'location_id' => $this->staff->staffAssignments()->first()->location_id,
            'lines' => $lines,
        ];

        $response = $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $payload, ['Idempotency-Key' => 'alloc-25pct']);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_FINANCE')
            ->assertJsonPath('data.over_target_pct', 25);

        $ledger = app(StockLedgerService::class);

        foreach ($products as $product) {
            $this->assertSame(0, $ledger->stockFor(StockLedgerService::CART, $this->cart->id, $product->id));
            $this->assertSame(200, $ledger->stockFor(StockLedgerService::KITCHEN, $this->cart->kitchen_id, $product->id));
        }
    }

    public function test_second_allocation_same_cart_same_day_without_correction_reason_is_conflict(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $this->payload(5), ['Idempotency-Key' => 'alloc-first'])
            ->assertStatus(201);

        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $this->payload(3), ['Idempotency-Key' => 'alloc-second'])
            ->assertStatus(409);
    }

    public function test_second_allocation_with_correction_reason_is_recorded_as_a_correction(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $this->payload(5), ['Idempotency-Key' => 'alloc-first-b'])
            ->assertStatus(201);

        $response = $this->actingAs($this->barista, 'sanctum')->postJson(
            '/api/v1/allocations',
            $this->payload(2, 'Koreksi kekurangan pengambilan pagi'),
            ['Idempotency-Key' => 'alloc-second-b'],
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.is_correction', true)
            ->assertJsonPath('data.correction_reason', 'Koreksi kekurangan pengambilan pagi');
    }

    public function test_insufficient_kitchen_stock_returns_422_and_moves_no_stock(): void
    {
        // Drain this product's kitchen stock from the seeded 200 down to 2.
        app(StockLedgerService::class)->post(
            StockLedgerService::KITCHEN,
            $this->cart->kitchen_id,
            $this->product->id,
            MovementType::ADJUSTMENT,
            -198,
            $this->barista->id,
            $this->cart->kitchen_id,
        );

        $response = $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/allocations', $this->payload(5), ['Idempotency-Key' => 'alloc-shortage']);

        $response->assertStatus(422);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(0, $ledger->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertSame(2, $ledger->stockFor(StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id));
    }
}
