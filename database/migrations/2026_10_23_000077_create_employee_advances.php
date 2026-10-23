<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advance_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });
        foreach (['Kasbon', 'Kasbon motor'] as $name) {
            DB::table('employee_advance_types')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('type_id')->constrained('employee_advance_types')->restrictOnDelete();
            $table->date('start_date');
            $table->unsignedBigInteger('principal_minor');
            $table->string('reason', 255);
            $table->string('installment_kind', 12);
            $table->unsignedBigInteger('installment_minor')->nullable();
            $table->string('source_kind', 12);
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status', 12)->default('ACTIVE');
            $table->string('cancel_reason', 225)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('employee_advance_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->date('entry_date');
            $table->string('kind', 24);
            $table->bigInteger('amount_minor');
            $table->string('reason', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['advance_id', 'entry_date']);
            $table->unique(['advance_id', 'journal_entry_id']);
        });

        Schema::create('employee_advance_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $table->string('kind', 24);
        });

        Schema::create('employee_advance_payroll_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'advance_id'], 'advance_payroll_override_unique');
        });

        foreach (['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role, 'module' => 'employee_advances',
                'abilities' => json_encode($role === 'ADMINISTRATOR' ? ['view'] : ['view', 'create', 'edit'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('employee_advances')->exists()
            || DB::table('employee_advance_movements')->exists()
            || DB::table('employee_advance_payroll_overrides')->exists()
            || DB::table('user_permissions')->where('module', 'employee_advances')->exists()) {
            throw new RuntimeException('Kasbon atau pengecualian akses masih ada. Simpan datanya sebelum rollback T-077.');
        }
        if (DB::table('employee_advance_types')->orderBy('name')->pluck('name')->all() !== ['Kasbon', 'Kasbon motor']) {
            throw new RuntimeException('Jenis kasbon sudah berubah. Simpan datanya sebelum rollback T-077.');
        }
        $grants = DB::table('role_permissions')->where('module', 'employee_advances')->get();
        if ($grants->count() !== 3 || $grants->contains(function ($grant): bool {
            $expected = $grant->role === 'ADMINISTRATOR' ? ['view'] : ['view', 'create', 'edit'];
            $actual = json_decode($grant->abilities, true, 512, JSON_THROW_ON_ERROR);

            return ! in_array($grant->role, ['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'], true)
                || $actual !== $expected;
        })) {
            throw new RuntimeException('Hak akses kasbon berubah. Simpan pengaturannya sebelum rollback T-077.');
        }
        DB::table('role_permissions')->where('module', 'employee_advances')->delete();
        Schema::dropIfExists('employee_advance_payroll_overrides');
        Schema::dropIfExists('employee_advance_operations');
        Schema::dropIfExists('employee_advance_movements');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('employee_advance_types');
    }
};
