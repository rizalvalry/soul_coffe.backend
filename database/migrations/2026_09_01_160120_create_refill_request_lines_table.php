<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refill_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refill_request_id')->constrained('refill_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // R4 monotonic chain: qty_requested >= qty_approved >= qty_prepared >= qty_received.
            // Each stage is null until that stage happens; enforcement of the chain itself
            // is a service-layer guard (422 on violation), never a silent clamp.
            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_approved')->nullable();
            $table->unsignedInteger('qty_prepared')->nullable();
            $table->unsignedInteger('qty_received')->nullable();

            // R10: cost pinned at submit time from the price version in effect then.
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('line_cost_minor'); // qty_requested * unit_cost_minor

            $table->timestamps();

            $table->unique(['refill_request_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refill_request_lines');
    }
};
