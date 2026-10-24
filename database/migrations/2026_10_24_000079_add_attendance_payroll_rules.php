<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->string('salary_basis', 16)->default('bulanan');
            $table->text('daily_rate_minor')->nullable();
        });

        Schema::create('payroll_attendance_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('late_after', 5)->default('09:00');
            $table->unsignedInteger('target_cups')->default(38);
            $table->timestamps();
        });

        Schema::create('payroll_monthly_bonus_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('min_cups')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_monthly_bonus_rules');
        Schema::dropIfExists('payroll_attendance_settings');
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropColumn(['salary_basis', 'daily_rate_minor']);
        });
    }
};
