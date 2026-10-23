<?php

use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INSERT_MARKER = '2026-10-22 00:01:11';

    public function up(): void
    {
        foreach (['ADMINISTRATOR', 'RIDER', 'FINANCE', 'MANAGEMENT'] as $role) {
            $abilities = $role === 'ADMINISTRATOR'
                ? ['view', 'create', 'edit', 'delete'] : ['view', 'edit'];
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'module' => 'cart_close_out',
                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
                'created_at' => self::INSERT_MARKER,
                'updated_at' => self::INSERT_MARKER,
            ]);
        }
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        // Matrix edits and account exceptions made after deployment remain untouched.
        DB::table('role_permissions')->where('module', 'cart_close_out')
            ->whereIn('role', ['RIDER', 'FINANCE', 'MANAGEMENT'])
            ->where('abilities', json_encode(['view', 'edit'], JSON_THROW_ON_ERROR))
            ->where('created_at', self::INSERT_MARKER)
            ->where('updated_at', self::INSERT_MARKER)
            ->whereNull('updated_by')->delete();
        DB::table('role_permissions')->where('module', 'cart_close_out')
            ->where('role', 'ADMINISTRATOR')
            ->where('abilities', json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR))
            ->where('created_at', self::INSERT_MARKER)
            ->where('updated_at', self::INSERT_MARKER)
            ->whereNull('updated_by')->delete();
        PermissionMatrix::forget();
    }
};
