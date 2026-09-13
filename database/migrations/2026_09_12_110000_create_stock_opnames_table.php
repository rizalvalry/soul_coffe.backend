<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Opname — the correction that lets the ledger and physical reality agree.
 *
 * WHY THIS HAD TO EXIST
 * ----------------------
 * `MovementType::ADJUSTMENT` existed since the ledger was written, but nothing ever posted it —
 * there was no form, no API, no menu that could. A stock ledger with no way to correct itself
 * against a physical count is a ledger that quietly drifts from reality forever, and a genuine
 * shrinkage (breakage, an uncounted waste, a miscount at a hand-over) has no honest way to be
 * recorded.
 *
 * TWO STEPS, LIKE SETTLEMENT'S RECORD-THEN-APPROVE
 * --------------------------------------------------
 * A physical count is taken by walking around a kitchen or a cart counting cups; entering that
 * count into the system is a separate moment, sometimes minutes later. `status` tracks which one
 * has happened:
 *
 *   DRAFT    — counts entered, nothing posted yet. Still cancellable, because nothing has
 *              touched the ledger.
 *   APPLIED  — the correction has been posted. Immutable from here, the same way a RECONCILED
 *              settlement is: a correction that can itself be silently corrected is not a
 *              correction, it is a second uncontrolled write.
 *   CANCELLED — a draft that was abandoned (wrong location, count taken by mistake) without ever
 *              touching stock.
 *
 * THE ADJUSTMENT POSTED AT APPLY TIME IS COMPUTED FRESH, NOT FROM THE DRAFT
 * ---------------------------------------------------------------------------
 * `system_qty_at_count` is what the ledger said at the moment the count was entered — kept for
 * the audit trail. The actual correction posted at apply time is measured against LIVE stock
 * under a row lock (see StockOpnameService::apply()), because a sale or a refill may have moved
 * stock in the gap between the physical count and someone clicking "Terapkan". The one honest
 * goal of an opname is "make the ledger equal what was physically counted", not "replay a
 * decision made minutes ago against numbers that may no longer be true".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // 'kitchen' | 'cart' — the same two location types StockLedgerService already knows.
            $table->string('location_type', 16);
            $table->unsignedBigInteger('location_id');

            $table->date('counted_date');
            $table->timestamp('counted_at');

            $table->string('status', 16)->default('DRAFT'); // DRAFT | APPLIED | CANCELLED

            // Why this count was taken — required, so a report built on this table can always
            // say what prompted it (routine count, a suspected shortage, a hand-over dispute).
            $table->string('reason', 500);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['location_type', 'location_id', 'counted_date']);
            $table->index('status');
        });

        Schema::create('stock_opname_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // What the ledger projected at the moment the count was entered — informational only,
            // kept so a reviewer can see whether stock moved between the count and the apply.
            $table->integer('system_qty_at_count');
            $table->unsignedInteger('counted_qty');
            // counted_qty - system_qty_at_count, computed at draft time for the operator's own
            // reference while entering counts.
            $table->integer('variance_qty');

            // Filled only once APPLIED — the live stock at the moment of posting, and the delta
            // actually written to the ledger (counted_qty - system_qty_at_apply). May differ from
            // `variance_qty` above when stock moved in the gap; that difference is exactly what
            // this pair of columns makes visible rather than silently overwriting.
            $table->integer('system_qty_at_apply')->nullable();
            $table->integer('applied_delta')->nullable();

            $table->timestamps();

            $table->unique(['stock_opname_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
        Schema::dropIfExists('stock_opnames');
    }
};
