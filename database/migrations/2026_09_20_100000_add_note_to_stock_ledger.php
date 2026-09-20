<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual stock adjustment (StockAdjustmentService) posts a single compensating row the moment
 * an operator says one figure is wrong — unlike Stock Opname, there is no separate `stock_opnames`
 * row alongside it to carry the reason. Without a place to put that reason on the ledger row
 * itself, the correction would be auditable only as "someone changed a number", which is exactly
 * the failure mode R6 (append-only, compensating entries) exists to prevent.
 *
 * Nullable and unused by every other movement type: a sale, a refill, a production run already
 * explains itself through `movement_type` and `ref_type`/`ref_id`. This column exists only for
 * the one movement a human decided to post by typing a reason rather than the system deriving it
 * from a workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->string('note', 500)->nullable()->after('ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
