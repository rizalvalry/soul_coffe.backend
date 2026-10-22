<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Users\UserAccessForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function create(bool $another = false): void
    {
        abort_if(($this->data['role'] ?? null) === Role::MANAGEMENT->value, 422);
        abort_if(! Auth::user()?->is_super_admin && ($this->data['is_management'] ?? false), 403);
        parent::create($another);
    }

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::USERS, 'create'), 403);
        $requestedRole = Role::tryFrom((string) ($this->data['role'] ?? $data['role'] ?? ''));
        abort_unless($requestedRole !== null && $requestedRole !== Role::MANAGEMENT
            && $requestedRole->value === ($data['role'] ?? null), 422);

        return DB::transaction(function () use ($data, $requestedRole): Model {
            User::query()->where('role', Role::ADMINISTRATOR->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = Auth::user()->refresh();
            PermissionMatrix::forget();
            abort_unless(PermissionMatrix::can($actor, PanelModule::USERS, 'create'), 403);
            if (! PermissionMatrix::canManagePermissions($actor)) {
                abort_if($requestedRole === Role::ADMINISTRATOR, 403);
                abort_if(collect($this->data['access_grants'] ?? [])
                    ->contains(fn ($choice): bool => $choice !== 'template'), 403);
                abort_if(collect($this->data['access_reasons'] ?? [])->contains(fn ($reason): bool => filled($reason)), 403);
            }
            abort_if(! $actor->is_super_admin && ($this->data['is_super_admin'] ?? false), 403);
            abort_if(! $actor->is_super_admin && ($this->data['is_management'] ?? false), 403);
            [$choices, $reasons, $superAdmin] = UserAccessForm::extract($data, $actor);
            /** @var User $user */
            $user = parent::handleRecordCreation($data);
            $user->employeeProfile()->create([]);
            if ($superAdmin) {
                $user->forceFill(['is_super_admin' => true])->save();
            }
            if ($choices !== [] || $reasons !== []) {
                UserAccessForm::apply($actor, $user, $choices, $reasons);
            }

            return $user;
        });
    }
}
