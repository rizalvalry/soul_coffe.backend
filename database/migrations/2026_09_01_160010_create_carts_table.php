<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // "kode sepeda", e.g. 0018
            $table->string('plate')->nullable();
            $table->string('status')->default('active'); // active | maintenance | retired
            // Additive beyond the literal §12 schema: which kitchen supplies this cart.
            // Required for multi-kitchen scoping (spec Q5) without joining through
            // staff_assignments/daily_allocations every time.
            $table->foreignId('kitchen_id')->nullable()
                ->constrained('central_kitchens')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
