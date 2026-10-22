<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Edits role templates under the Super Administrator bootstrap rule.
 *
 * The access-settings module is excluded because its grant is intentionally ineffective.
 *
 * Only Super Administrator can open it after bootstrap. Until then, ordinary Administrator
 * may set templates. Administrator itself remains an editable role template.
 *
 * Saving writes only the role on screen, so two administrators editing different roles cannot
 * clobber each other's work.
 */
class RoleAccessMatrix extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Management Users Role';

    /**
     * Renameable under the key `role_matrix`, like every other menu.
     *
     * The page is hardcoded to Administrator and stays that way — but its NAME is copy like any
     * other, and the request was that every name in the panel be changeable.
     */
    public static function getNavigationLabel(): string
    {
        return MenuLabels::for('role_matrix');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Pengaturan');
    }

    public function getTitle(): string
    {
        return MenuLabels::for('role_matrix');
    }

    protected static ?string $title = 'Management Users Role';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.role-access-matrix';

    public const ABILITIES = ['view' => 'Lihat', 'create' => 'Tambah', 'edit' => 'Ubah', 'delete' => 'Hapus'];

    /** The role being edited. */
    public string $role = '';

    /** @var array<string, array<string, bool>> module => ability => granted */
    public array $grants = [];

    public static function canAccess(): bool
    {
        return PermissionMatrix::canManagePermissions(Auth::user());
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
        return array_values(array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::MANAGEMENT));
    }

    /** @return array<int, PanelModule> */
    public function modules(): array
    {
        return array_values(array_filter(PanelModule::cases(),
            fn (PanelModule $module): bool => $module !== PanelModule::ROLE_MATRIX));
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

    public function isInherited(PanelModule $module, string $ability): bool
    {
        return false;
    }

    public function canSave(): bool
    {
        return PermissionMatrix::canManagePermissions(Auth::user());
    }

    public function needsSuperAdmin(): bool
    {
        return ! User::query()->where('role', Role::ADMINISTRATOR->value)
            ->where('is_super_admin', true)->where('is_active', true)->exists();
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $role = Role::from($this->role);
        abort_if($role === Role::MANAGEMENT, 422);

        DB::transaction(function () use ($role): void {
            User::query()->where('role', Role::ADMINISTRATOR->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            Auth::user()->refresh();
            abort_unless($this->canSave(), 403);
            $changed = false;
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

                $changed = PermissionMatrix::set($role, $module, $abilities, Auth::user(), notify: false) || $changed;
            }
            if ($changed) {
                PermissionMatrix::notifyRoleChange($role);
            }
        });

        $this->loadGrants();

        Notification::make()
            ->success()
            ->title('Hak akses tersimpan')
            ->body(sprintf('Hak akses untuk peran %s sudah diperbarui.', $role->label()))
            ->send();
    }

    private function loadGrants(): void
    {
        PermissionMatrix::forget();

        $role = Role::from($this->role);
        abort_if($role === Role::MANAGEMENT, 422);
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
