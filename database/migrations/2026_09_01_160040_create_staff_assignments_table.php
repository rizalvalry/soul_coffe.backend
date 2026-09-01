<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('operating_date');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            // Additive beyond §12: denormalised from carts.kitchen_id at assignment time
            // so kitchen-scoped queries (R: "barista must not leak across kitchens") don't
            // need a join through carts.
            $table->foreignId('kitchen_id')->nullable()
                ->constrained('central_kitchens')->nullOnDelete();
            $table->timestamps();

            // R11: a staff member has exactly one cart per operating day.
            $table->unique(['user_id', 'operating_date']);
            // One staff per cart per day.
            $table->unique(['cart_id', 'operating_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignments');
    }
};
