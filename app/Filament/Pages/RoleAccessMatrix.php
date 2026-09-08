<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Where an Administrator decides which menus each role may reach, and how far.
 *
 * Two deliberate refusals:
 *
 *  1. **This page is hardcoded to ADMINISTRATOR** and is not itself a module in the matrix. A
 *     screen that can grant permissions must never be grantable through the permissions it
 *     edits, or any role given it once can give itself everything.
 *  2. **ADMINISTRATOR is not listed as an editable role.** PermissionMatrix passes it before
 *     reading the table at all, so an "unchecked" box would be a lie; showing the role with a
 *     locked note is honest, hiding it silently is not.
 *
 * Saving writes only the role on screen, so two administrators editing different roles cannot
 * clobber each other's work.
 */
class RoleAccessMatrix extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Matriks Akses Peran';

    protected static ?string $title = 'Matriks Akses Peran';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.role-access-matrix';

    public const ABILITIES = ['view' => 'Lihat', 'create' => 'Tambah', 'edit' => 'Ubah', 'delete' => 'Hapus'];

    /** The role being edited. */
    public string $role = '';

    /** @var array<string, array<string, bool>> module => ability => granted */
    public array $grants = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function mount(): void
    {
        $this->role = Role::FINANCE->value;
        $this->loadGrants();
    }

    /** Re-reads the checkboxes whenever the role dropdown changes. */
    public function updatedRole(): void
    {
        $this->loadGrants();
    }

    /** @return array<int, Role> every role the matrix can actually govern. */
    public function editableRoles(): array
    {
        return array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $role !== Role::ADMINISTRATOR,
        ));
    }

    /** @return array<int, PanelModule> */
    public function modules(): array
    {
        return PanelModule::cases();
    }

    /** @return array<string, string> the abilities offered for one module. */
    public function abilitiesFor(PanelModule $module): array
    {
        // A read-only module has nothing to create, edit or delete — offering those boxes would
        // let an administrator tick a permission that can never do anything.
        return $module->isReadOnly()
            ? ['view' => self::ABILITIES['view']]
            : self::ABILITIES;
    }

    public function save(): void
    {
        $role = Role::from($this->role);

        foreach ($this->modules() as $module) {
            $allowed = array_keys($this->abilitiesFor($module));

            $abilities = array_values(array_filter(
                $allowed,
                fn (string $ability): bool => (bool) ($this->grants[$module->value][$ability] ?? false),
            ));

            // "Tambah/Ubah/Hapus without Lihat" is a menu the role can act on but never see. It
            // is always a mistake, so view is implied rather than enforced with an error.
            if ($abilities !== [] && ! in_array('view', $abilities, true)) {
                $abilities[] = 'view';
            }

            PermissionMatrix::set($role, $module, $abilities, Auth::user());
        }

        $this->loadGrants();

        Notification::make()
            ->success()
            ->title('Matriks akses tersimpan')
            ->body(sprintf('Hak akses untuk peran %s sudah diperbarui.', $role->label()))
            ->send();
    }

    private function loadGrants(): void
    {
        PermissionMatrix::forget();

        $role = Role::from($this->role);
        $grants = [];

        foreach ($this->modules() as $module) {
            $held = PermissionMatrix::abilitiesFor($role, $module);

            foreach (array_keys($this->abilitiesFor($module)) as $ability) {
                $grants[$module->value][$ability] = in_array($ability, $held, true);
            }
        }

        $this->grants = $grants;
    }
}
