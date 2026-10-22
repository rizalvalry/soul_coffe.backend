<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('absen_radius_m');
            $table->unsignedInteger('customer_radius_m');
            $table->boolean('absen_requires_gps');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        DB::table('location_settings')->insert([
            'id' => 1,
            'absen_radius_m' => (int) config('soul.absen_geofence_m', 10),
            'customer_radius_m' => 2000,
            'absen_requires_gps' => (bool) config('soul.absen_requires_gps', true),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'module' => 'location_settings',
                'abilities' => json_encode(['view', 'edit'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $setting = DB::table('location_settings')->first();
        $grants = DB::table('role_permissions')->where('module', 'location_settings')->get();
        if (DB::table('central_kitchens')->where('geofence_m', 0)->exists()
            || DB::table('locations')->where('geofence_m', 0)->exists()
            || $setting && ((int) $setting->version !== 1
            || (int) $setting->absen_radius_m !== (int) config('soul.absen_geofence_m', 10)
            || (int) $setting->customer_radius_m !== 2000
            || (bool) $setting->absen_requires_gps !== (bool) config('soul.absen_requires_gps', true))
            || $grants->count() !== 3
            || $grants->contains(fn ($grant): bool => ! in_array($grant->role, ['ADMINISTRATOR', 'FINANCE', 'MANAGEMENT'], true)
                || json_decode($grant->abilities, true, 512, JSON_THROW_ON_ERROR) !== ['view', 'edit'])) {
            throw new RuntimeException('Ganti dulu radius dapur/titik yang pakai bawaan, lalu simpan perubahan pengaturan dan hak akses sebelum rollback T-070.');
        }
        DB::table('role_permissions')->where('module', 'location_settings')->delete();
        Schema::dropIfExists('location_settings');
    }
};
