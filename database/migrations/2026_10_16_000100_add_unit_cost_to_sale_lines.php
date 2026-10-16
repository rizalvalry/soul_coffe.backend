<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL ALTER TABLE is not transactional. A retry must finish a partially applied run.
        if (! Schema::hasColumn('sale_lines', 'unit_cost_minor')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('unit_cost_minor')->nullable()->after('unit_price_minor');
            });
        }

        if (! Schema::hasColumn('direct_sale_lines', 'unit_cost_minor')) {
            Schema::table('direct_sale_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('unit_cost_minor')->nullable()->after('unit_price_minor');
            });
        }
    }

    public function down(): void
    {
        // Keep historical HPP recoverable if this migration is rolled back after sales/backfill.
        if ((Schema::hasColumn('sale_lines', 'unit_cost_minor')
                && DB::table('sale_lines')->whereNotNull('unit_cost_minor')->exists())
            || (Schema::hasColumn('direct_sale_lines', 'unit_cost_minor')
                && DB::table('direct_sale_lines')->whereNotNull('unit_cost_minor')->exists())) {
            throw new RuntimeException('Cannot drop populated sale cost snapshots.');
        }

        if (Schema::hasColumn('direct_sale_lines', 'unit_cost_minor')) {
            Schema::table('direct_sale_lines', function (Blueprint $table): void {
                $table->dropColumn('unit_cost_minor');
            });
        }

        if (Schema::hasColumn('sale_lines', 'unit_cost_minor')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                $table->dropColumn('unit_cost_minor');
            });
        }
    }
};
