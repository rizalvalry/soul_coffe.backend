<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();

            // Polymorphic by hand (not morphs()) because the two possible tables
            // (central_kitchens, carts) don't share a base class and a real FK would
            // have to pick one — the pair is validated in the model/service layer.
            $table->string('location_type'); // 'kitchen' | 'cart'
            $table->unsignedBigInteger('location_id');

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('movement_type'); // MovementType

            // R7 governs the *quantities* entered by users (qty_requested etc.), not
            // this ledger delta: a delta must be able to go negative (an OUT movement)
            // so that SUM(qty_delta) is directly the projected stock. IN movements are
            // written positive, OUT movements negative — enforced by the ledger-writing
            // service, never by the caller.
            $table->integer('qty_delta');

            $table->string('ref_type')->nullable(); // e.g. 'daily_allocation', 'refill_request'
            $table->unsignedBigInteger('ref_id')->nullable();

            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            // Additive beyond §12: directly queryable kitchen scope even for rows at a
            // cart location, so "all movements under kitchen X" needs no join through
            // carts (multi-kitchen requirement, spec Q5).
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();

            // R6 — APPEND ONLY. No updated_at column exists on purpose: this table is
            // never UPDATEd or DELETEd. A correction is a new compensating row (e.g. an
            // ADJUSTMENT with the opposite sign), never an edit of a past row. If you
            // are about to add an `updated_at` here or write an UPDATE against this
            // table, stop — that breaks R6 and the audit trail it exists to guarantee.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['location_type', 'location_id', 'product_id', 'created_at'], 'stock_ledger_location_product_idx');
            $table->index(['kitchen_id', 'product_id', 'created_at']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
