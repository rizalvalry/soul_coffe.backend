<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale can be voided. Until this migration, none could — a staff member who tapped the wrong
 * product or the wrong quantity had no way back, and the cart's stock and the day's revenue
 * stayed wrong permanently.
 *
 * VOIDED, NOT DELETED
 * -------------------
 * `deleted_at` would let a row vanish from every report with no trace of what happened. A voided
 * sale stays exactly where it was, with `voided_at`/`voided_by`/`void_reason` recording who undid
 * it and why — the same append-only discipline the stock ledger already keeps. Every aggregate
 * that reads `sales` (SalesActivityService, SettlementService, StaffLocationService,
 * SaleController, SaleResource's badge) is updated in the same change to exclude a voided row,
 * because a void that only hides itself from one screen is worse than no void at all.
 *
 * THE STOCK COMES BACK THROUGH THE LEDGER, NOT BY EDITING THE SALE
 * ------------------------------------------------------------------
 * Voiding posts a compensating `SALE_VOID_IN` movement for each line, exactly the quantity the
 * original `SALE_OUT` removed. The ledger stays append-only; nothing here rewrites a row that
 * already happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('is_suspect');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable()->after('voided_by');

            // Every read of this table filters "not voided" first — see the services listed
            // above — so that predicate deserves its own index rather than a full scan per
            // aggregate.
            $table->index(['operating_date', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['operating_date', 'voided_at']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
