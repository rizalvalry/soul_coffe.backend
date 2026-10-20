<?php

use Database\Seeders\AccountSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(AccountSeeder::class)->run();

        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->boolean('cost_pending')->default(false);
            $table->unsignedInteger('cost_pending_qty')->default(0);
            $table->index(['cost_pending', 'product_id', 'id'], 'stock_pending_product_idx');
        });

        Schema::create('stock_cost_revaluation_locks', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->primary();
        });

        Schema::create('stock_cost_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_ledger_id')->unique()->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('product_price_version_id')->nullable()->constrained('product_price_versions')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->bigInteger('resolved_cost_minor');
            $table->timestamp('resolved_at');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('stock_cost_resolutions') && DB::table('stock_cost_resolutions')->exists())
            || DB::table('stock_ledger')->where('cost_pending', true)->exists()
            || DB::table('journal_lines')->whereIn('account_id', DB::table('accounts')->where('code', '6-1920')->select('id'))->exists()) {
            throw new RuntimeException('Biaya stok tertunda atau jurnal penilaiannya masih ada. Simpan datanya sebelum rollback.');
        }

        Schema::dropIfExists('stock_cost_resolutions');
        Schema::dropIfExists('stock_cost_revaluation_locks');
        // An earlier in-development index order may be the FK's only product_id index on
        // a local test DB. Keep the FK supported before dropping that temporary index.
        $otherProductIndex = collect(Schema::getIndexes('stock_ledger'))->contains(
            fn (array $index): bool => $index['name'] !== 'stock_pending_product_idx'
                && ($index['columns'][0] ?? null) === 'product_id',
        );
        if (! $otherProductIndex) {
            Schema::table('stock_ledger', function (Blueprint $table): void {
                $table->index('product_id', 'stock_ledger_product_fk_support_idx');
            });
        }
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropIndex('stock_pending_product_idx');
            $table->dropColumn('cost_pending');
            $table->dropColumn('cost_pending_qty');
        });
        DB::table('accounts')->where('code', '6-1920')->where('is_system', true)->delete();
    }
};
