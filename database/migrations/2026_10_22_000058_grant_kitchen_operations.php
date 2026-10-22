<?php

use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Distinguish grants inserted here from identical pre-existing grants on rollback.
    private const INSERT_MARKER = '2026-10-22 00:00:58';

    public function up(): void
    {
        $abilities = json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR);
        foreach ([Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT, Role::BARISTA] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role->value,
                'module' => 'kitchen_operations',
                'abilities' => $abilities,
                'created_at' => self::INSERT_MARKER,
                'updated_at' => self::INSERT_MARKER,
            ]);
        }
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        // Keep rows that an administrator changed after deployment.
        DB::table('role_permissions')->where('module', 'kitchen_operations')
            ->whereIn('role', [Role::ADMINISTRATOR->value, Role::FINANCE->value,
                Role::MANAGEMENT->value, Role::BARISTA->value])
            ->where('abilities', json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR))
            ->where('created_at', self::INSERT_MARKER)
            ->where('updated_at', self::INSERT_MARKER)
            ->whereNull('updated_by')->delete();
        PermissionMatrix::forget();
    }
};
