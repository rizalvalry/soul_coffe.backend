<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_incentive_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->char('payload_hash', 64);
        });
        Schema::create('rider_incentive_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('kind', 28);
            $table->bigInteger('amount_minor');
            $table->string('reason', 255)->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->foreignId('commission_lock_id')->nullable()->unique()->constrained('rider_daily_commission_locks')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('advance_id')->nullable()->constrained('employee_advances')->restrictOnDelete();
            $table->foreignId('corrects_movement_id')->nullable()->unique()->constrained('rider_incentive_movements')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['rider_id', 'entry_date', 'id']);
            $table->index('entry_date');
            $table->unique(['rider_id', 'journal_entry_id']);
        });

        foreach ([
            ['2-1800', 'Utang Insentif Rider', 'LIABILITY', 'CREDIT'],
            ['6-2300', 'Beban Insentif Penjualan Rider', 'EXPENSE', 'DEBIT'],
        ] as [$code, $name, $type, $normal]) {
            DB::table('accounts')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => $normal, 'is_active' => true, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role, 'module' => 'rider_incentive_balance',
                'abilities' => json_encode($role === 'ADMINISTRATOR'
                    ? ['view', 'create', 'edit', 'delete'] : ['view', 'create', 'edit'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('rider_incentive_movements')->exists()
            || DB::table('rider_incentive_operations')->exists()
            || DB::table('user_permissions')->where('module', 'rider_incentive_balance')->exists()) {
            throw new RuntimeException('Mutasi atau pengecualian akses insentif masih ada. Simpan datanya sebelum rollback T-078.');
        }
        $grants = DB::table('role_permissions')->where('module', 'rider_incentive_balance')->get();
        if ($grants->count() !== 3 || $grants->contains(fn ($grant): bool => ! in_array($grant->role, ['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'], true)
            || json_decode($grant->abilities, true, 512, JSON_THROW_ON_ERROR) !== ($grant->role === 'ADMINISTRATOR'
                ? ['view', 'create', 'edit', 'delete'] : ['view', 'create', 'edit']))) {
            throw new RuntimeException('Hak akses insentif berubah. Simpan pengaturannya sebelum rollback T-078.');
        }
        DB::table('role_permissions')->where('module', 'rider_incentive_balance')->delete();
        Schema::dropIfExists('rider_incentive_movements');
        Schema::dropIfExists('rider_incentive_operations');
        // Shared chart accounts may predate this migration or be used by other journals.
    }
};
