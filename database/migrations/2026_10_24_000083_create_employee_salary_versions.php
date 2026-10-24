<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('salary_basis', 16);
            $table->text('base_salary_minor')->nullable();
            $table->text('daily_rate_minor')->nullable();
            $table->timestamps();
            $table->unique(['employee_profile_id', 'effective_from'], 'employee_salary_effective_unique');
        });

        DB::table('employee_profiles')->orderBy('id')->chunkById(100, function ($profiles): void {
            foreach ($profiles as $profile) {
                DB::table('employee_salary_versions')->insert([
                    'employee_profile_id' => $profile->id,
                    'effective_from' => '2026-10-01',
                    'salary_basis' => $profile->salary_basis,
                    'base_salary_minor' => $profile->base_salary_minor,
                    'daily_rate_minor' => $profile->daily_rate_minor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('employee_salary_versions')->whereDate('effective_from', '>', '2026-10-01')->exists()
            || DB::table('audit_log')->where('action', 'employee_salary_version_saved')
                ->whereNotNull('before_json')->exists()) {
            throw new RuntimeException('Riwayat perubahan gaji sudah ada; simpan riwayatnya sebelum rollback migrasi ini.');
        }
        Schema::dropIfExists('employee_salary_versions');
    }
};
