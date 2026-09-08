<?php

namespace Database\Seeders;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Seeder;

/**
 * The only grants that ship out of the box: FINANCE gets the absensi module.
 *
 * That pairing is the requirement this module was built for ("bisa di CRUD oleh administrator dan
 * finance"), so it is a default rather than something an administrator has to discover and switch
 * on. Everything else stays empty — ADMINISTRATOR passes the matrix unconditionally, and no other
 * role is given anything it was not asked to have. Adjust the rest in the panel under
 * Pengaturan → Matriks Akses Peran.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $full = ['view', 'create', 'edit', 'delete'];

        PermissionMatrix::set(Role::FINANCE, PanelModule::PARTNERS, $full);
        PermissionMatrix::set(Role::FINANCE, PanelModule::PARTNER_ATTENDANCE, $full);
    }
}
