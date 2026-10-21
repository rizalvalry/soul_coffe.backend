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

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::USERS, 'create'), 403);
        abort_unless(PermissionMatrix::canManagePermissions(Auth::user()), 403);

        return DB::transaction(function () use ($data): Model {
            User::query()->where('role', Role::ADMINISTRATOR->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = Auth::user()->refresh();
            abort_unless(PermissionMatrix::can($actor, PanelModule::USERS, 'create')
                && PermissionMatrix::canManagePermissions($actor), 403);
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
