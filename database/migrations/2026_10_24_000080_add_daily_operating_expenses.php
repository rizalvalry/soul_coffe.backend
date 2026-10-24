<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_unit_price_minor')->nullable();
            $table->string('unit', 32)->nullable();
            $table->boolean('is_daily')->default(false);
        });

        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->boolean('is_daily_expense')->default(false);
            $table->foreignId('employee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->index(['date', 'employee_id']);
        });
        Schema::create('cash_daily_batches', function (Blueprint $table): void {
            $table->uuid('request_key')->primary();
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('row_count');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (DB::table('cash_daily_batches')->exists()
            || DB::table('cash_categories')->whereNotNull('default_unit_price_minor')->exists()
            || DB::table('cash_categories')->whereNotNull('unit')->exists()
            || DB::table('cash_categories')->where('is_daily', true)->exists()
            || DB::table('cash_transactions')->whereNotNull('unit_price_minor')->exists()
            || DB::table('cash_transactions')->where('is_daily_expense', true)->exists()
            || DB::table('cash_transactions')->whereNotNull('employee_id')->exists()
            || DB::table('cash_transactions')->whereNotNull('location_id')->exists()) {
            throw new RuntimeException('Preserve daily expense values before rolling back T-080.');
        }

        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropIndex(['date', 'employee_id']);
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['unit_price_minor', 'is_daily_expense']);
        });
        Schema::table('cash_categories', function (Blueprint $table): void {
            $table->dropColumn(['default_unit_price_minor', 'unit', 'is_daily']);
        });
        Schema::dropIfExists('cash_daily_batches');
    }
};
