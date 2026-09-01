<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('target_qty'); // R7: positive integers, cups not divisible
            // 0 = Sunday .. 6 = Saturday; null = applies every day of the week.
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->timestamps();

            $table->index(['cart_id', 'weekday']);
            $table->index(['location_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_targets');
    }
};
