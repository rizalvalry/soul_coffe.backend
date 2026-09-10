<?php

namespace Database\Seeders;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Seeder;

/**
 * The two grants that ship out of the box, both to FINANCE: the absensi report, and the absen
 * exemptions that go with it.
 *
 * Note what it does NOT get: `users`. The employment profile the sheet reads (NIK, size, jatah
 * klibur) lives on the user record now, and the Users menu is also where roles, passwords and
 * PINs are changed — so handing Finance edit rights there to let them set a quota would hand
 * them the ability to change who is an Administrator. Filling the sheet needs neither. An
 * Administrator maintains the profile fields; Finance records the days.
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

        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, $full);

        // "Administrator atau finance berhak melakukan modifikasi" — the people who reconcile the
        // money are the people who know which cart is at an event this week, so Finance gets the
        // exemption menu with the attendance report rather than having to be granted it later by
        // somebody who has not yet realised it exists.
        PermissionMatrix::set(Role::FINANCE, PanelModule::ABSEN_EXEMPTIONS, $full);

        // The deposits Finance itself records on the phone. Read-only by nature (see
        // PanelModule::isReadOnly), so this grant is the report, not a second way to edit one.
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
    }
}
