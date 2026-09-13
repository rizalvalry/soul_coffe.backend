<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — raw material stock lives in this SAME ledger, under `location_type =
 * 'raw_material_store'` (location_id = kitchen_id, same numbering space as 'kitchen'), rather
 * than a parallel table. `product_id` becomes nullable and `raw_material_id` is added alongside
 * it: exactly one of the two is populated per row, so a report built on this table always knows
 * which catalogue a movement belongs to without a second join to guess.
 *
 * `cost_minor` is the total cost of the line when one is known (a purchase receipt fills it; a
 * recipe consumption does not, since a cost was already paid for the ingredient — see
 * StockLedgerService::post()). Nullable, because most movements never had a purchase price
 * attached to them (a PRODUCTION_IN of a finished cup, for instance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('raw_material_id')->nullable()->after('product_id')
                ->constrained('raw_materials')->restrictOnDelete();
            $table->bigInteger('cost_minor')->nullable()->after('qty_delta');

            $table->index(['location_type', 'location_id', 'raw_material_id', 'created_at'], 'stock_ledger_location_raw_material_idx');
        });

        // MySQL 8.0.16+ enforces CHECK constraints; guarded so this migration stays safe to run
        // against a driver that only parses and ignores them (or none at all). The application-
        // level guard in StockLedgerService::post() is the real, portable enforcement — see there.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_exactly_one_catalogue_chk '.
                'CHECK ((product_id IS NULL) <> (raw_material_id IS NULL))'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_ledger DROP CONSTRAINT stock_ledger_exactly_one_catalogue_chk');
        }

        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropIndex('stock_ledger_location_raw_material_idx');
            $table->dropConstrainedForeignId('raw_material_id');
            $table->dropColumn('cost_minor');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
