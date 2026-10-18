<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_closings', function (Blueprint $table) {
            $table->id();
            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cogs_minor');
            $table->unsignedBigInteger('qris_minor');
            $table->unsignedBigInteger('transfer_minor');
            $table->unsignedBigInteger('cash_minor');
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->boolean('gps_unavailable')->default(false);
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['rider_id', 'cart_id', 'operating_date'], 'rider_closing_rider_cart_day');
            $table->index(['cart_id', 'operating_date']);
        });

        Schema::create('rider_closing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_closing_id')->constrained('rider_closings')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty_in');
            $table->unsignedInteger('qty_carry');
            $table->unsignedInteger('qty_remaining');
            $table->unsignedInteger('qty_wasted');
            $table->unsignedInteger('qty_sold');
            $table->unsignedInteger('qty_sale_posted');
            $table->unsignedInteger('qty_waste_posted');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unique(['rider_closing_id', 'product_id']);
        });

        Schema::create('rider_closing_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_closing_id')->constrained('rider_closings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot_json');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('rider_closing_idempotency_keys', function (Blueprint $table) {
            $table->char('key_hash', 64)->primary();
            $table->char('request_hash', 64);
            $table->foreignId('rider_closing_id')->constrained('rider_closings')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('cart_closeout_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('rider_closing_id')->constrained('rider_closings')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->date('operating_date');
            $table->unsignedInteger('qty_closing_remaining');
            $table->unsignedInteger('qty_counted');
            $table->integer('variance_qty');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['cart_id', 'operating_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_closeout_variances');
        Schema::dropIfExists('rider_closing_idempotency_keys');
        Schema::dropIfExists('rider_closing_revisions');
        Schema::dropIfExists('rider_closing_lines');
        Schema::dropIfExists('rider_closings');
    }
};
