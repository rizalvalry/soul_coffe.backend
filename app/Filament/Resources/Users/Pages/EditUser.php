<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        foreach (['national_id', 'bank_account_number'] as $field) {
            $data[$field] = (Auth::user()?->role->grants(Role::MANAGEMENT) ?? false)
                ? $user->getAttribute($field)
                : User::maskSensitiveValue($user->getAttribute($field));
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
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
