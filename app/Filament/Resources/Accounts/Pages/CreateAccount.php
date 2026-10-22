<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Enums\PanelModule;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\AuditLog;
use App\Services\Access\PermissionMatrix;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::CHART_OF_ACCOUNTS, 'edit'), 403);
        if (! in_array($data['type'], ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'COGS', 'EXPENSE'], true)) {
            throw ValidationException::withMessages(['type' => 'Jenis akun tidak valid.']);
        }
        if (! empty($data['parent_id']) && Account::query()->whereKey($data['parent_id'])
            ->where('type', $data['type'])->where('is_active', true)->doesntExist()) {
            throw ValidationException::withMessages(['parent_id' => 'Akun induk harus aktif dan sejenis.']);
        }

        return DB::transaction(function () use ($data): Account {
            $account = Account::query()->create([
                ...$data,
                'normal_balance' => in_array($data['type'], ['ASSET', 'COGS', 'EXPENSE'], true) ? 'DEBIT' : 'CREDIT',
            ]);
            AuditLog::recordAction(Auth::user(), 'account.created', $account, null,
                $account->only(['code', 'name', 'type', 'normal_balance', 'parent_id']));

            return $account;
        });
    }
}
