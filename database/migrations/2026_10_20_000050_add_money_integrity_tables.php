<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('self_approved')->default(false);
            $table->boolean('method_variance_flagged')->default(false);
            $table->bigInteger('cash_variance_minor')->nullable();
            $table->bigInteger('qris_variance_minor')->nullable();
            $table->bigInteger('transfer_variance_minor')->nullable();
        });

        Schema::create('clearing_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('received_on');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('mdr_minor')->default(0);
            $table->json('sale_dates_json')->nullable();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 8)->default('POSTED');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('clearing_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clearing_receipt_id')->constrained('clearing_receipts')->restrictOnDelete();
            $table->date('sale_date');
            $table->date('effective_on');
            $table->unsignedBigInteger('amount_minor');
            $table->index(['clearing_receipt_id', 'sale_date']);
        });

        Schema::create('clearing_match_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clearing_receipt_id')->constrained('clearing_receipts')->restrictOnDelete();
            $table->json('sale_dates_json');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('accounts')->insertOrIgnore([
            'code' => '6-1990', 'name' => 'Biaya Admin/MDR', 'type' => 'EXPENSE',
            'normal_balance' => 'DEBIT', 'is_active' => true, 'is_system' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('clearing_receipts')->exists()) {
            throw new RuntimeException('Simpan data dana masuk sebelum rollback migrasi ini.');
        }
        if (DB::table('settlements')->whereNotNull('recorded_by')->exists()) {
            throw new RuntimeException('Simpan jejak pencatat setoran sebelum rollback migrasi ini.');
        }
        Schema::dropIfExists('clearing_match_requests');
        Schema::dropIfExists('clearing_allocations');
        Schema::dropIfExists('clearing_receipts');
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['self_approved', 'method_variance_flagged', 'cash_variance_minor', 'qris_variance_minor', 'transfer_variance_minor']);
        });
        // Keep the additive system account: it may have existed before this migration or
        // already be referenced by an older journal, and deleting either loses accounting data.
    }
};
