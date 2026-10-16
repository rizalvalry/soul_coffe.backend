<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DirectSale;
use App\Models\DirectSaleLine;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillSaleCostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_and_backfill_use_transaction_time_leave_existing_snapshots_and_are_idempotent(): void
    {
        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $staff = User::factory()->role(Role::STAFF)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $product = $this->product('KOPI');
        $unpriced = $this->product('UNPRICED');

        $oldTime = Carbon::now()->subDays(5)->startOfSecond();
        ProductPriceVersion::create([
            'product_id' => $product->id, 'cost_price_minor' => 7000,
            'sell_price_minor' => 15000, 'effective_from' => $oldTime->copy()->subDay(),
        ])->forceFill(['created_at' => $oldTime->copy()->subDays(2)])->saveQuietly();
        $newTime = Carbon::now()->subDay();
        ProductPriceVersion::create([
            'product_id' => $product->id, 'cost_price_minor' => 9000,
            'sell_price_minor' => 20000, 'effective_from' => $newTime,
        ])->forceFill(['created_at' => $newTime])->saveQuietly();
        ProductPriceVersion::create([
            'product_id' => $product->id, 'cost_price_minor' => 10000,
            'sell_price_minor' => 21000, 'effective_from' => $newTime,
        ])->forceFill(['created_at' => $newTime])->saveQuietly();
        // Entered later and backdated; it was not available when the old sale occurred.
        ProductPriceVersion::create([
            'product_id' => $product->id, 'cost_price_minor' => 14000,
            'sell_price_minor' => 15000, 'effective_from' => $oldTime->copy()->subDay(),
        ]);
        // Same-second correction with the same selling price is also too late to trust.
        ProductPriceVersion::create([
            'product_id' => $product->id, 'cost_price_minor' => 18000,
            'sell_price_minor' => 15000, 'effective_from' => $oldTime->copy()->subHour(),
        ])->forceFill(['created_at' => $oldTime])->saveQuietly();

        $oldLine = $this->saleLine($cart, $staff, $product, $oldTime->copy()->subHours(2));
        $sameSecondLine = $this->saleLine($cart, $staff, $product, $oldTime);
        $missingLine = $this->saleLine($cart, $staff, $unpriced, $oldTime);
        $mismatchLine = $this->saleLine($cart, $staff, $product, Carbon::now());
        $existingLine = $this->saleLine($cart, $staff, $product, $oldTime, 6000);
        $directLine = $this->directLine($cart, $finance, $product, Carbon::now());

        $this->artisan('soul:backfill-sale-costs --dry-run')
            ->expectsOutput('sale_lines: dapat diisi 1, tidak dapat diisi 3.')
            ->expectsOutput('direct_sale_lines: dapat diisi 1, tidak dapat diisi 0.')
            ->assertExitCode(0);
        $this->assertNull($oldLine->fresh()->unit_cost_minor);
        $this->assertNull($directLine->fresh()->unit_cost_minor);

        $this->artisan('soul:backfill-sale-costs')
            ->expectsOutput('sale_lines: diisi 1, tidak dapat diisi 3.')
            ->expectsOutput('direct_sale_lines: diisi 1, tidak dapat diisi 0.')
            ->assertExitCode(0);
        $this->assertSame(7000, $oldLine->fresh()->unit_cost_minor);
        $this->assertNull($sameSecondLine->fresh()->unit_cost_minor);
        $this->assertSame(10000, $directLine->fresh()->unit_cost_minor);
        $this->assertSame(6000, $existingLine->fresh()->unit_cost_minor);
        $this->assertNull($missingLine->fresh()->unit_cost_minor);
        $this->assertNull($mismatchLine->fresh()->unit_cost_minor);

        $this->artisan('soul:backfill-sale-costs')
            ->expectsOutput('sale_lines: diisi 0, tidak dapat diisi 3.')
            ->expectsOutput('direct_sale_lines: diisi 0, tidak dapat diisi 0.')
            ->assertExitCode(0);
    }

    private function product(string $code): Product
    {
        return Product::create([
            'code' => $code, 'name' => $code, 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function saleLine(Cart $cart, User $staff, Product $product, Carbon $time, ?int $cost = null): SaleLine
    {
        $sale = Sale::create([
            'uuid' => (string) Str::uuid(), 'operating_date' => $time->toDateString(),
            'cart_id' => $cart->id, 'staff_id' => $staff->id, 'occurred_at' => $time,
            'total_qty' => 1, 'total_amount_minor' => 15000,
        ]);

        return SaleLine::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'qty' => 1,
            'unit_price_minor' => 15000, 'unit_cost_minor' => $cost, 'subtotal_minor' => 15000,
        ]);
    }

    private function directLine(Cart $cart, User $finance, Product $product, Carbon $time): DirectSaleLine
    {
        $sale = DirectSale::create([
            'uuid' => (string) Str::uuid(), 'cart_id' => $cart->id,
            'recorded_by' => $finance->id, 'occurred_at' => $time,
            'total_qty' => 1, 'total_amount_minor' => 21000,
        ]);

        return DirectSaleLine::create([
            'direct_sale_id' => $sale->id, 'product_id' => $product->id, 'qty' => 1,
            'unit_price_minor' => 21000, 'subtotal_minor' => 21000,
        ]);
    }
}
