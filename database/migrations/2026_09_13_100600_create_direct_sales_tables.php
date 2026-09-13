<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in sale at the central kitchen/office — a buyer standing in front of Finance, not a
 * staff member out on a cart route.
 *
 * A SEPARATE TABLE, NOT A ROW IN `sales`
 * --------------------------------------
 * `sales` is "a staff member sold cups from their assigned cart", and every reader of it
 * (SalesActivityService, SettlementService, StaffLocationService, SaleResource) is built on that
 * one meaning — staff attribution, an operating-date settlement cycle, an area/hour trail. A
 * walk-in sale shares none of that shape: it is entered by Finance or Administrator, from
 * whichever cart happens to be sitting at the office (assigned or not), with no GPS and no
 * settlement of its own. Folding it into `sales` would either bend every one of those readers
 * around a case they were never designed for, or silently corrupt them. A new table keeps the
 * existing gerobak-sale reporting exactly as it is today.
 *
 * THE STOCK MECHANISM IS IDENTICAL TO A CART SALE
 * ------------------------------------------------
 * Cups still leave a cart's stock, so this reuses `MovementType::SALE_OUT` /
 * `MovementType::SALE_VOID_IN` against the same `stock_ledger` (`ref_type` distinguishes the two
 * origins) rather than inventing a parallel movement vocabulary for what is mechanically the same
 * fact: stock left a cart because someone bought it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            // Server clock (R16), same reasoning as `sales.occurred_at`.
            $table->timestamp('occurred_at');

            $table->unsignedInteger('total_qty');
            $table->unsignedBigInteger('total_amount_minor')->default(0);
            $table->string('payment_method', 16)->default('cash'); // cash | qris | transfer

            $table->text('note')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            $table->timestamps();

            $table->index(['cart_id', 'occurred_at']);
            $table->index(['occurred_at', 'voided_at']);
        });

        Schema::create('direct_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direct_sale_id')->constrained('direct_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->timestamps();

            $table->unique(['direct_sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_sale_lines');
        Schema::dropIfExists('direct_sales');
    }
};
