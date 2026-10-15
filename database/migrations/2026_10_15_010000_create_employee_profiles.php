<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Roster accounts have no phone until an administrator completes onboarding.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_e164')->nullable()->change();
            $table->boolean('roster_onboarding')->default(false);
        });

        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('division')->nullable()->index();
            $table->string('position_title')->nullable();
            $table->string('employment_status', 16)->nullable();
            $table->text('base_salary_minor')->nullable();
            $table->string('ptkp_status', 8)->nullable();
            $table->text('npwp')->nullable();
            $table->boolean('bpjs_tk_enrolled')->default(false);
            $table->boolean('bpjs_kes_enrolled')->default(false);
            $table->boolean('payroll_active')->default(false);
            $table->foreignId('default_cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $table->timestamps();

            $table->index('payroll_active');
        });
    }

    public function down(): void
    {
        // A rollback must not silently discard imported HR data or restore NOT NULL over it.
        if (DB::table('employee_profiles')->exists() || DB::table('users')->whereNull('phone_e164')->exists()) {
            throw new RuntimeException('Resolve employee profiles and phone-less roster accounts before rolling back T-006.');
        }

        Schema::dropIfExists('employee_profiles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('roster_onboarding');
            $table->string('phone_e164')->nullable(false)->change();
        });
    }
};
