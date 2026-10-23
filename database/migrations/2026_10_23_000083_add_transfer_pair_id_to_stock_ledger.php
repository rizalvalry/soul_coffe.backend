<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->uuid('transfer_pair_id')->nullable()->index();
        });
        Schema::table('stock_reconciliation_results', function (Blueprint $table): void {
            $table->json('history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_reconciliation_results', function (Blueprint $table): void {
            $table->dropColumn('history');
        });
        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropIndex(['transfer_pair_id']);
            $table->dropColumn('transfer_pair_id');
        });
    }
};
