<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\User;
use App\Services\RiderCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiderCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $finance;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->cart = Cart::query()->create(['code' => 'COMMISSION-TEST', 'status' => 'active']);
        $this->product = Product::query()->create([
            'code' => 'COMMISSION-CUP', 'name' => 'Cup Uji', 'unit' => 'cup', 'sort_order' => 1,
        ]);
    }

    private function closing(string $date, int $cups): RiderClosing
    {
        $closing = RiderClosing::query()->create([
            'rider_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'operating_date' => $date, 'total_minor' => 0, 'cogs_minor' => 0,
            'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
            'closed_at' => $date.' 18:00:00',
        ]);
        $closing->lines()->create([
            'product_id' => $this->product->id, 'qty_in' => $cups, 'qty_carry' => 0,
            'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => $cups,
            'qty_sale_posted' => $cups, 'qty_waste_posted' => 0,
            'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
        ]);

        return $closing;
    }

    public function test_exact_tier_boundaries_missing_closing_and_effective_versions(): void
    {
        $service = app(RiderCommissionService::class);
        $service->save([$this->rider->id], '2026-10-01', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 1000],
            ['min_cups' => 200, 'amount_per_cup_minor' => 1500],
        ], $this->finance, (string) Str::uuid());
        $this->assertSame(0, $service->day($this->rider->id, '2026-10-01')['commission_minor']);
        $this->assertFalse($service->day($this->rider->id, '2026-10-01')['has_closing']);

        foreach (['2026-10-02' => 99, '2026-10-03' => 100, '2026-10-04' => 199, '2026-10-05' => 200] as $date => $cups) {
            $this->closing($date, $cups);
        }
        $expected = [
            '2026-10-02' => [0, null, 1],
            '2026-10-03' => [100000, 100, 100],
            '2026-10-04' => [199000, 100, 1],
            '2026-10-05' => [300000, 200, null],
        ];
        foreach ($expected as $date => [$amount, $tier, $remaining]) {
            $day = $service->day($this->rider->id, $date);
            $this->assertSame($amount, $day['commission_minor']);
            $this->assertSame($tier, $day['tier_min_cups']);
            $this->assertSame($remaining, $day['cups_to_next_tier']);
        }

        $service->save([$this->rider->id], '2026-10-06', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 2000],
        ], $this->finance, (string) Str::uuid());
        $this->closing('2026-10-06', 100);
        $this->assertSame(200000, $service->day($this->rider->id, '2026-10-06')['commission_minor']);
        $this->assertSame(100000, $service->day($this->rider->id, '2026-10-03')['commission_minor']);
    }

    public function test_version_history_is_append_only_and_request_retry_is_idempotent(): void
    {
        $service = app(RiderCommissionService::class);
        $key = (string) Str::uuid();
        $rows = [['min_cups' => 100, 'amount_per_cup_minor' => 1000]];
        $service->save([$this->rider->id], '2026-10-01', $rows, $this->finance, $key);
        $service->save([$this->rider->id], '2026-10-01', $rows, $this->finance, $key);
        $this->assertDatabaseCount('rider_commission_versions', 1);
        $this->assertDatabaseCount('rider_commission_tiers', 1);
        $service->save([$this->rider->id], '2026-10-01', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 2000],
        ], $this->finance, (string) Str::uuid());
        $this->assertDatabaseCount('rider_commission_versions', 2);
        $this->assertSame(2000, $service->tiersFor($this->rider->id, '2026-10-01')[0]['amount_per_cup_minor']);
    }

    public function test_pack_lines_do_not_raise_cup_tier_or_commission(): void
    {
        $service = app(RiderCommissionService::class);
        $service->save([$this->rider->id], '2026-10-01', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 1000],
        ], $this->finance, (string) Str::uuid());
        $closing = $this->closing('2026-10-08', 99);
        $pack = Product::query()->create(['code' => 'COMMISSION-PACK', 'name' => 'Pack Uji', 'unit' => 'pack', 'sort_order' => 2]);
        $closing->lines()->create([
            'product_id' => $pack->id, 'qty_in' => 200, 'qty_carry' => 0,
            'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => 200,
            'qty_sale_posted' => 200, 'qty_waste_posted' => 0,
            'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
        ]);
        $this->assertSame(99, $service->day($this->rider->id, '2026-10-08')['cups_sold']);
        $this->assertSame(0, $service->day($this->rider->id, '2026-10-08')['commission_minor']);
        $closing->lines()->where('product_id', $this->product->id)->update(['qty_sold' => 100]);
        $this->assertSame(100000, $service->day($this->rider->id, '2026-10-08')['commission_minor']);
    }

    public function test_product_unit_is_frozen_after_closing_even_without_legacy_sale(): void
    {
        $service = app(RiderCommissionService::class);
        $service->save([$this->rider->id], '2026-10-01', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 1000],
        ], $this->finance, (string) Str::uuid());
        $this->closing('2026-10-08', 100);
        try {
            $this->product->update(['unit' => 'pack']);
            $this->fail('A Closing cup product must keep its unit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
        $this->assertSame('cup', $this->product->fresh()->unit);
        $this->assertSame(100000, $service->day($this->rider->id, '2026-10-08')['commission_minor']);
    }
}
