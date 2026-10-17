<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            foreach (['jht', 'jp', 'jkk', 'jkm', 'health'] as $part) {
                $table->unsignedInteger($part.'_employer_bps')->default(0);
                $table->unsignedInteger($part.'_employee_bps')->default(0);
            }
            $table->unsignedBigInteger('jp_wage_cap_minor')->default(0);
            $table->unsignedBigInteger('health_wage_cap_minor')->default(0);
            $table->text('source_note');
            $table->timestamps();
        });

        Schema::create('ptkp_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 8);
            $table->string('ter_category', 1);
            $table->unsignedBigInteger('annual_amount_minor');
            $table->date('effective_from');
            $table->text('source_note');
            $table->timestamps();
            $table->unique(['status', 'effective_from']);
        });

        Schema::create('payroll_ter_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 1);
            $table->date('effective_from');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['category', 'effective_from']);
        });

        Schema::create('pph21_ter_brackets', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 1);
            $table->unsignedBigInteger('lower_gross_minor');
            $table->unsignedBigInteger('upper_gross_minor')->nullable();
            $table->unsignedInteger('rate_bps');
            $table->date('effective_from');
            $table->text('source_note');
            $table->timestamps();
            $table->unique(['category', 'effective_from', 'lower_gross_minor'], 'ter_bracket_version_unique');
        });

        Schema::create('rider_incentive_rules', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->unsignedBigInteger('amount_per_cup_minor')->default(0);
            $table->unsignedInteger('target_cups')->default(0);
            $table->unsignedBigInteger('bonus_minor')->default(0);
            $table->text('source_note');
            $table->timestamps();
        });

        Schema::create('payroll_component_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->string('kind', 16);
            $table->boolean('taxable');
            $table->date('effective_from');
            $table->text('source_note');
            $table->timestamps();
            $table->unique(['code', 'effective_from']);
        });

        Schema::create('payroll_component_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('component_type_id')->constrained('payroll_component_types')->restrictOnDelete();
            $table->date('period_start');
            $table->uuid('request_key')->unique();
            // Encrypted through PayrollComponentEntry, like the employee's base salary.
            $table->text('amount_minor');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'period_start']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->index(['staff_id', 'operating_date', 'voided_at'], 'sales_payroll_cups_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('sales', 'sales_payroll_cups_index')) {
            // MySQL may replace the staff_id FK's original index with our composite index.
            if (! Schema::hasIndex('sales', 'sales_staff_id_foreign')) {
                Schema::table('sales', function (Blueprint $table): void {
                    $table->index('staff_id', 'sales_staff_id_foreign');
                });
            }
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropIndex('sales_payroll_cups_index');
            });
        }
        Schema::dropIfExists('payroll_component_entries');
        Schema::dropIfExists('payroll_component_types');
        Schema::dropIfExists('rider_incentive_rules');
        Schema::dropIfExists('pph21_ter_brackets');
        Schema::dropIfExists('payroll_ter_versions');
        Schema::dropIfExists('ptkp_rates');
        Schema::dropIfExists('payroll_settings');
    }
};
