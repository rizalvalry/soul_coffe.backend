<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — how raw material stock enters the system traceably, with a real cost.
 * DRAFT -> ORDERED -> RECEIVED, a strict one-way lifecycle with a side exit to CANCELLED from
 * either of the first two states. See PurchaseOrderService for why RECEIVED can never be
 * cancelled: it has already posted to the stock ledger (PURCHASE_IN), and correcting a posted
 * fact is a Stock Opname's job, not this one's — the same sale-void-vs-settlement boundary this
 * codebase already draws elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT'); // DRAFT | ORDERED | RECEIVED | CANCELLED
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kitchen_id', 'status']);
            $table->index('status');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('raw_material_id')->constrained('raw_materials')->restrictOnDelete();
            $table->unsignedInteger('qty_ordered');
            $table->bigInteger('unit_cost_minor'); // whole-rupiah cost per unit (R9)
            $table->unsignedInteger('qty_received')->nullable(); // filled at receive time
            $table->timestamps();

            $table->unique(['purchase_order_id', 'raw_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
