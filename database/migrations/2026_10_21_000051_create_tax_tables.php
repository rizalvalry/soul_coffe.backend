<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_migration_accounts', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
        });

        Schema::create('tax_setting_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_type', 8);
            $table->boolean('is_active');
            $table->unsignedSmallInteger('rate_bps');
            $table->string('price_mode', 16)->nullable();
            $table->string('scheme', 16)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('legal_note')->nullable();
            $table->unsignedBigInteger('monthly_threshold_minor')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tax_type', 'effective_from']);
        });

        Schema::create('tax_remittances', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_type', 8);
            $table->date('paid_on');
            $table->unsignedBigInteger('amount_minor');
            $table->string('receipt_number');
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 80)->unique();
            $table->string('status', 8)->default('POSTED');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        foreach ([
            ['2-1500', 'Utang PBJT', 'LIABILITY'],
            ['2-1600', 'Utang PPh Badan', 'LIABILITY'],
            ['6-1950', 'Beban PPh Final', 'EXPENSE'],
        ] as [$code, $name, $type]) {
            $inserted = DB::table('accounts')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => $type === 'EXPENSE' ? 'DEBIT' : 'CREDIT',
                'is_active' => true, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1) {
                DB::table('tax_migration_accounts')->insert(['code' => $code]);
            }
        }
    }

    public function down(): void
    {
        $ownedCodes = Schema::hasTable('tax_migration_accounts')
            ? DB::table('tax_migration_accounts')->pluck('code')->all() : [];
        if (DB::table('tax_remittances')->exists() || DB::table('tax_setting_versions')->exists()
            || DB::table('journal_lines')->whereIn('account_id', DB::table('accounts')->whereIn('code', $ownedCodes)->select('id'))->exists()) {
            throw new RuntimeException('Simpan data pajak dan jurnal sebelum rollback migrasi pajak.');
        }
        Schema::dropIfExists('tax_remittances');
        Schema::dropIfExists('tax_setting_versions');
        DB::table('accounts')->whereIn('code', $ownedCodes)->delete();
        Schema::dropIfExists('tax_migration_accounts');
    }
};
