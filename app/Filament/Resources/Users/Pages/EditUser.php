<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Users\UserAccessForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\Account;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_if(($this->data['role'] ?? null) === Role::MANAGEMENT->value, 422);
        abort_if(! Auth::user()?->is_super_admin && array_key_exists('is_management', $this->data)
            && (bool) $this->data['is_management'] !== (bool) $this->record->is_management, 403);
        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        foreach (['national_id', 'bank_account_number'] as $field) {
            $data[$field] = PermissionMatrix::can(Auth::user(), PanelModule::PAYROLL, 'edit')
                ? $user->getAttribute($field)
                : User::maskSensitiveValue($user->getAttribute($field));
        }

        $data['access_grants'] = [];
        $data['access_reasons'] = [];
        foreach ($user->permissions as $permission) {
            $data['access_grants'][$permission->module] = $permission->abilities === [] ? 'none'
                : (array_intersect($permission->abilities, ['create', 'edit', 'delete']) ? 'edit' : 'view');
            $data['access_reasons'][$permission->module] = $permission->reason;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $requestedRole = (string) ($this->data['role'] ?? $data['role'] ?? $record->role->value);
        abort_if($requestedRole === Role::MANAGEMENT->value, 422);

        return DB::transaction(function () use ($record, $data, $requestedRole): Model {
            if ($requestedRole !== $record->role->value) {
                Account::query()->orderBy('id')->lockForUpdate()->first();
            }
            User::query()->where('role', Role::ADMINISTRATOR->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = Auth::user()->refresh();
            $record->refresh();
            abort_if(! $actor->is_super_admin && array_key_exists('is_management', $this->data)
                && (bool) $this->data['is_management'] !== (bool) $record->is_management, 403);
            if (! PermissionMatrix::canManagePermissions($actor)
                && ($record->role === Role::ADMINISTRATOR || $requestedRole !== $record->role->value)) {
                abort(403);
            }
            [$choices, $reasons, $superAdmin] = UserAccessForm::extract($data, $actor);
            $updated = parent::handleRecordUpdate($record, $data);
            if ($superAdmin !== null && $updated->is_super_admin !== $superAdmin) {
                $updated->forceFill(['is_super_admin' => $superAdmin])->save();
            }
            if ($choices !== [] || $reasons !== []) {
                UserAccessForm::apply($actor, $updated, $choices, $reasons);
            }

            return $updated;
        });
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        if (! $user->roster_onboarding || ! ($data['is_active'] ?? false)) {
            return $data;
        }

        $errors = [];
        if (blank($data['phone_e164'] ?? null)) {
            $errors['data.phone_e164'] = 'Nomor HP wajib diisi sebelum akun impor diaktifkan.';
        }
        if (blank($data['password'] ?? null)) {
            $errors['data.password'] = 'Buat kata sandi sebelum akun impor diaktifkan.';
        }
        $role = Role::tryFrom((string) ($data['role'] ?? $user->role->value));
        if ($role === Role::STAFF && blank($data['pin_hash'] ?? null)) {
            $errors['data.pin_hash'] = 'Buat PIN Rider sebelum akun impor diaktifkan.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['roster_onboarding'] = false;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
