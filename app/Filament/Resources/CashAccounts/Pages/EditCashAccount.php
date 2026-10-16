<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Models\Account;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCashAccount extends EditRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->transactions()->exists() && $this->record->account_id !== (int) $data['account_id']) {
            throw ValidationException::withMessages(['account_id' => 'Akun aset yang sudah dipakai tidak dapat diubah.']);
        }
        if (! Account::query()->whereKey($data['account_id'])->where('type', 'ASSET')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['account_id' => 'Akun kas harus aset aktif.']);
        }

        return $data;
    }
}
