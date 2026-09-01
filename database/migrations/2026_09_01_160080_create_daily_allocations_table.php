<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_allocations', function (Blueprint $table) {
            $table->id();
            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
            $table->foreignId('barista_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status'); // AllocationStatus
            $table->boolean('is_correction')->default(false);
            $table->text('correction_reason')->nullable();
            $table->unsignedInteger('over_target_pct')->default(0);
            // References the FINANCE user who approved an over-target allocation
            // (Flow A invariant, Q2) — null while status is not PENDING_FINANCE/APPROVED.
            $table->foreignId('finance_approval_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // One allocation per cart per day is the invariant (Flow A); a second
            // row for the same cart/day is deliberately allowed as a `correction`
            // (is_correction = true, correction_reason required) and is audited as
            // such at the application layer, not blocked here by a unique index.
            $table->index(['cart_id', 'operating_date']);
            $table->index(['kitchen_id', 'operating_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_allocations');
    }
};
