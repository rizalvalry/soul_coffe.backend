<?php

use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GRANT_MARKER = '2026-10-24 00:08:02';

    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('bike_type', 40)->nullable();
        });

        Schema::create('finance_daily_targets', function (Blueprint $table): void {
            $table->id();
            $table->date('operating_date')->unique();
            $table->unsignedInteger('company_cups');
            $table->unsignedInteger('rider_cups');
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        foreach (['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'module' => 'finance_daily_report',
                'abilities' => json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR),
                'created_at' => self::GRANT_MARKER,
                'updated_at' => self::GRANT_MARKER,
            ]);
        }
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        if (DB::table('finance_daily_targets')->exists() || DB::table('carts')->whereNotNull('bike_type')->exists()) {
            throw new RuntimeException('Rollback T-082 ditahan: simpan dulu target harian dan jenis Coffee Bike yang sudah diisi.');
        }
        DB::table('role_permissions')->where('module', 'finance_daily_report')
            ->where('abilities', json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR))
            ->where('created_at', self::GRANT_MARKER)
            ->where('updated_at', self::GRANT_MARKER)
            ->whereNull('updated_by')->delete();
        Schema::dropIfExists('finance_daily_targets');
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('bike_type');
        });
        PermissionMatrix::forget();
    }
};
