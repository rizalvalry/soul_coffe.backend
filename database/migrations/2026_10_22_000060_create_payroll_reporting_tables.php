<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t053_seeded_accounts', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
        });
        foreach ([
            ['2-1700', 'Cadangan THR', 'LIABILITY'],
            ['2-1710', 'Utang THR', 'LIABILITY'],
            ['6-2200', 'Beban THR', 'EXPENSE'],
        ] as [$code, $name, $type]) {
            if (DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => $type === 'EXPENSE' ? 'DEBIT' : 'CREDIT',
                'is_active' => true, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('t053_seeded_accounts')->insert(['code' => $code]);
        }

        Schema::create('thr_setting_versions', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->json('component_type_ids');
            $table->unsignedSmallInteger('minimum_months')->default(1);
            $table->unsignedSmallInteger('full_months')->default(12);
            $table->unsignedSmallInteger('prorata_denominator')->default(12);
            $table->unsignedSmallInteger('reminder_days')->default(7);
            $table->boolean('reserve_enabled')->default(false);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('thr_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->date('planned_payment_date');
            $table->foreignId('setting_version_id')->nullable()->constrained('thr_setting_versions')->restrictOnDelete();
            $table->string('status', 12)->default('DRAFT');
            $table->string('idempotency_key', 80)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('thr_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('thr_runs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->longText('snapshot');
            $table->string('slip_path')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'user_id']);
        });
        Schema::create('thr_provisions', function (Blueprint $table): void {
            $table->id();
            $table->date('month')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('setting_version_id')->constrained('thr_setting_versions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_remittances', function (Blueprint $table): void {
            $table->id();
            $table->date('month');
            $table->string('kind', 8);
            $table->string('program', 12)->nullable();
            $table->date('paid_on');
            $table->unsignedBigInteger('amount_minor');
            $table->string('receipt_number', 255);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('idempotency_key', 80)->unique();
            $table->string('status', 8)->default('POSTED');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 225)->nullable();
            $table->timestamps();
            $table->index(['month', 'kind', 'program']);
        });

        Schema::create('cash_flow_mappings', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained('accounts')->restrictOnDelete();
            $table->string('category', 12);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('financial_budgets', function (Blueprint $table): void {
            $table->id();
            $table->date('month');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->restrictOnDelete();
            $table->unsignedBigInteger('cart_scope')->default(0);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['month', 'account_id', 'cart_scope']);
        });
        Schema::create('financial_kpi_targets', function (Blueprint $table): void {
            $table->string('metric', 40)->primary();
            $table->unsignedBigInteger('target_value');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->foreignId('cart_id')->nullable()->constrained('carts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['thr_runs', 'thr_provisions', 'payroll_remittances', 'financial_budgets', 'financial_kpi_targets', 'cash_flow_mappings'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Data T-053 masih ada; ekspor dan koreksi dulu sebelum rollback.');
            }
        }
        if (DB::table('cash_transactions')->whereNotNull('cart_id')->exists()) {
            throw new RuntimeException('Tag Coffee Bike pada kas masih ada; rollback dibatalkan.');
        }
        foreach (DB::table('t053_seeded_accounts')->pluck('code') as $code) {
            $id = DB::table('accounts')->where('code', $code)->value('id');
            if (DB::table('journal_lines')->where('account_id', $id)->exists()) {
                throw new RuntimeException('Jurnal THR masih memakai akun '.$code.'; rollback dibatalkan.');
            }
        }
        Schema::table('cash_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('cart_id'));
        Schema::dropIfExists('financial_kpi_targets');
        Schema::dropIfExists('financial_budgets');
        Schema::dropIfExists('cash_flow_mappings');
        Schema::dropIfExists('payroll_remittances');
        Schema::dropIfExists('thr_provisions');
        Schema::dropIfExists('thr_items');
        Schema::dropIfExists('thr_runs');
        Schema::dropIfExists('thr_setting_versions');
        foreach (DB::table('t053_seeded_accounts')->pluck('code') as $code) {
            $id = DB::table('accounts')->where('code', $code)->value('id');
            DB::table('accounts')->where('id', $id)->delete();
        }
        Schema::dropIfExists('t053_seeded_accounts');
    }
};
