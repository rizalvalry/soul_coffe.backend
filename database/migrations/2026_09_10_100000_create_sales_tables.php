<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cups actually sold from a cart — the movement that was missing from the whole system.
 *
 * `SALE_OUT` has existed in MovementType since the ledger was written but nothing ever posted
 * it: stock went from kitchen to cart and then simply sat there. So the morning mapping was a
 * target nobody could measure against, and `settlements.qty_sold` had no source. This is that
 * source.
 *
 * NOT the same thing as `settlements`, which is the one-row-per-cart-per-day money
 * reconciliation. This is per transaction, because the question being asked of it — "Pulomas at
 * 09:00 vs Cempaka Mas at 10:00" — cannot be answered by a daily total.
 *
 * Why each column earns its place, given the brief was "as few fields as possible":
 *  - `occurred_at` is server time (R16). A phone clock decides nothing here, because the whole
 *    point is comparing hours across carts.
 *  - `gps_*` is captured at the moment of sale. Location per TRANSACTION is what makes the area
 *    analysis real; a daily assignment only says where somebody was supposed to be.
 *  - `location_id` is the assigned selling point, kept alongside GPS so an area can still be
 *    grouped when GPS was unavailable (E10: never a hard block).
 *  - `total_amount_minor` pins the price at sale time, the same way R10 pins refill cost. A price
 *    change next month must not rewrite last month's revenue.
 *  - `is_suspect` is a flag, never a veto — see SaleService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            // Client-generated, like refill requests (R14): a staff member tapping submit twice
            // on a bad connection must end up with one sale, not two.
            $table->uuid('uuid')->unique();
            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamp('occurred_at');

            $table->unsignedInteger('total_qty');
            $table->unsignedBigInteger('total_amount_minor')->default(0);
            $table->string('payment_method', 16)->default('cash'); // cash | qris | transfer

            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->boolean('gps_unavailable')->default(false);

            // Anti-fraud signal only. The transaction is always recorded.
            $table->boolean('is_suspect')->default(false);
            $table->string('suspect_reason')->nullable();

            $table->string('note')->nullable();
            $table->string('device_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            // The two reads this table exists for: one cart's day, and one area's hour.
            $table->index(['operating_date', 'cart_id']);
            $table->index(['occurred_at']);
            $table->index(['location_id', 'occurred_at']);
            $table->index('is_suspect');
        });

        Schema::create('sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            // Pinned at sale time (R9: whole rupiah, scale 0).
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->timestamps();

            // One line per product per sale — two lines for the same cup would double the stock
            // movement and the revenue.
            $table->unique(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
