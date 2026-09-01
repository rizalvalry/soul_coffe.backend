<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained('daily_allocations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('target_qty'); // pre-filled from daily_targets at issue time
            $table->unsignedInteger('qty_issued');
            $table->timestamps();

            $table->unique(['allocation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_allocation_lines');
    }
};
