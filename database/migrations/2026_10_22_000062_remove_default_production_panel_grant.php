<?php

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOTS = 'production_panel_grant_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS)) {
            Schema::create(self::SNAPSHOTS, function (Blueprint $table): void {
                $table->unsignedBigInteger('role_permission_id')->primary();
                $table->json('abilities');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        DB::transaction(function (): void {
            if (DB::table(self::SNAPSHOTS)->exists()) {
                return;
            }
            $row = DB::table('role_permissions')
                ->where('role', Role::BARISTA->value)
                ->where('module', PanelModule::RIDER_LOCATION_SETUP->value)
                ->lockForUpdate()->first();
            if ($row === null || $row->updated_by !== null
                || json_decode($row->abilities, true, 512, JSON_THROW_ON_ERROR) !== ['view']) {
                return;
            }

            DB::table(self::SNAPSHOTS)->insert([
                'role_permission_id' => $row->id,
                'abilities' => $row->abilities,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            DB::table('role_permissions')->where('id', $row->id)->delete();
        });
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $snapshot = DB::table(self::SNAPSHOTS)->first();
            if ($snapshot === null) {
                return;
            }

            $current = DB::table('role_permissions')
                ->where('role', Role::BARISTA->value)
                ->where('module', PanelModule::RIDER_LOCATION_SETUP->value)
                ->lockForUpdate()->first();
            if ($current !== null) {
                throw new RuntimeException('Hak Setup Lokasi Rider Produksi sudah diubah setelah migrasi; rollback dibatalkan agar perubahan Administrator tetap aman.');
            }

            DB::table('role_permissions')->insert([
                'id' => $snapshot->role_permission_id,
                'role' => Role::BARISTA->value,
                'module' => PanelModule::RIDER_LOCATION_SETUP->value,
                'abilities' => $snapshot->abilities,
                'updated_by' => null,
                'created_at' => $snapshot->created_at,
                'updated_at' => $snapshot->updated_at,
            ]);
        });

        Schema::drop(self::SNAPSHOTS);
        PermissionMatrix::forget();
    }
};
