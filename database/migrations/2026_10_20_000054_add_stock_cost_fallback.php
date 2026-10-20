<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->boolean('cost_fallback')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('stock_ledger')->where('cost_fallback', true)->exists()) {
            throw new RuntimeException('Baris stok dengan biaya fallback masih ada. Simpan datanya sebelum rollback.');
        }
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropColumn('cost_fallback');
        });
    }
};
