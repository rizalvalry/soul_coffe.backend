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
        (new AccountSeeder)->run();

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('payment_method', 8)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
        });

        Schema::create('purchase_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('paid_on');
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('paid_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_order_payments')->exists()
            || DB::table('purchase_orders')->whereNotNull('payment_method')->exists()) {
            throw new RuntimeException('PO accounting data must be preserved before rollback: payment records or journaled receipts still exist.');
        }

        Schema::dropIfExists('purchase_order_payments');
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropColumn('payment_method');
        });
    }
};
