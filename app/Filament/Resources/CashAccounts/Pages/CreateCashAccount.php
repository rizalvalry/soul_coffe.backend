<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Models\Account;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCashAccount extends CreateRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! Account::query()->whereKey($data['account_id'])->where('type', 'ASSET')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['account_id' => 'Akun kas harus aset aktif.']);
        }

        return $data;
    }
}
