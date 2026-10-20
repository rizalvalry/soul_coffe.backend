<?php

use Database\Seeders\AccountSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACCOUNT_CODES = ['1-1800', '1-1900', '6-1910', '6-2000', '6-2100'];

    public function up(): void
    {
        app(AccountSeeder::class)->run();

        Schema::create('stock_valuation_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('location_type', 32);
            $table->unsignedBigInteger('location_id');
            $table->string('catalogue_type', 16);
            $table->unsignedBigInteger('catalogue_id');
            $table->bigInteger('quantity')->default(0);
            $table->bigInteger('value_minor')->default(0);
            $table->boolean('initialized')->default(false);
            $table->unique(['location_type', 'location_id', 'catalogue_type', 'catalogue_id'], 'stock_cost_lock_unique');
        });

        Schema::create('fixed_asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach (['Coffee Bike', 'Mesin kopi', 'Peralatan dapur', 'Lainnya'] as $name) {
            DB::table('fixed_asset_categories')->insert(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('fixed_asset_categories')->restrictOnDelete();
            $table->date('acquired_on');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedSmallInteger('useful_life_months');
            $table->unsignedBigInteger('residual_minor')->default(0);
            $table->string('location')->nullable();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->restrictOnDelete();
            $table->string('status', 12)->default('ACTIVE');
            $table->string('acquisition_mode', 12);
            $table->foreignId('funding_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('disposed_on')->nullable();
            $table->unsignedBigInteger('disposal_proceeds_minor')->nullable();
            $table->foreignId('disposal_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'acquired_on']);
        });
    }

    public function down(): void
    {
        if (DB::table('fixed_assets')->exists()
            || DB::table('fixed_asset_categories')->whereNotIn('name', ['Coffee Bike', 'Mesin kopi', 'Peralatan dapur', 'Lainnya'])->exists()
            || DB::table('fixed_asset_categories')->where('is_active', false)->exists()
            || DB::table('journal_entries')->whereIn('source_type', ['fixed_asset', 'fixed_asset_depreciation', 'fixed_asset_disposal'])->exists()
            || DB::table('journal_lines')->whereIn('account_id', DB::table('accounts')->whereIn('code', self::ACCOUNT_CODES)->select('id'))->exists()) {
            throw new RuntimeException('Data aset atau jurnal akun baru masih ada. Simpan data sebelum rollback.');
        }
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
        Schema::dropIfExists('stock_valuation_locks');
        DB::table('accounts')->whereIn('code', self::ACCOUNT_CODES)->where('is_system', true)->delete();
    }
};
