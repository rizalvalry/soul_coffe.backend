<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // R9: money is BIGINT rupiah, scale = 0. No floats, no decimals.
            $table->unsignedBigInteger('cost_price_minor'); // HPP — Finance/Admin only (R15)
            $table->unsignedBigInteger('sell_price_minor');
            $table->timestamp('effective_from');
            $table->timestamps();

            // Never edited in place (R10) — a price change is a new row. The "current"
            // price for a product is the latest row with effective_from <= now().
            $table->index(['product_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_versions');
    }
};
