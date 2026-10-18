<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payroll_settings', 'ptkp_rates', 'payroll_ter_versions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->text('verification_note')->nullable();
            });
        }
        Schema::table('payroll_ter_versions', fn (Blueprint $table) => $table->text('source_note')->nullable());
        Schema::table('payroll_component_types', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        DB::table('payroll_component_types')->where('code', 'ADVANCE')->update([
            'account_id' => DB::table('accounts')->where('code', '1-1500')->value('id'),
        ]);
        DB::table('payroll_component_types')->where('code', 'OTHER_DEDUCTION')->update([
            'account_id' => DB::table('accounts')->where('code', '4-1300')->value('id'),
        ]);

        Schema::create('payroll_annual_tax_versions', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->text('source_note');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
        });
        Schema::create('payroll_annual_tax_brackets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('payroll_annual_tax_versions')->restrictOnDelete();
            $table->unsignedBigInteger('lower_taxable_minor');
            $table->unsignedBigInteger('upper_taxable_minor')->nullable();
            $table->unsignedInteger('rate_bps');
            $table->timestamps();
            $table->unique(['version_id', 'lower_taxable_minor'], 'annual_tax_bracket_unique');
        });
        Schema::create('payroll_prior_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->text('amount_minor'); // EncryptedInteger cast.
            $table->text('gross_minor'); // EncryptedInteger cast; actual missing-month gross.
            $table->text('jht_jp_minor'); // EncryptedInteger cast; actual missing-month JHT/JP.
            $table->text('months'); // Encrypted array of covered month numbers.
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'year']);
        });
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start')->unique();
            $table->string('status', 12)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->longText('snapshot'); // Encrypted JSON; includes component rows.
            $table->string('slip_path')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_prior_withholdings');
        Schema::dropIfExists('payroll_annual_tax_brackets');
        Schema::dropIfExists('payroll_annual_tax_versions');
        Schema::table('payroll_component_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        foreach (['payroll_ter_versions', 'ptkp_rates', 'payroll_settings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('verified_by');
                $table->dropColumn(['verified_at', 'verification_note']);
            });
        }
        Schema::table('payroll_ter_versions', fn (Blueprint $table) => $table->dropColumn('source_note'));
    }
};
