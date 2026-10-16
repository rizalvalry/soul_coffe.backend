<?php

namespace App\Filament\Resources\CashCategories\Pages;

use App\Filament\Resources\CashCategories\CashCategoryResource;
use App\Models\Account;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCashCategory extends CreateRecord
{
    protected static string $resource = CashCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = Account::query()->whereKey($data['account_id'])->where('is_active', true)->value('type');
        if (! in_array($type, $data['direction'] === 'OUT' ? ['EXPENSE', 'ASSET'] : ['REVENUE', 'EQUITY'], true)) {
            throw ValidationException::withMessages(['account_id' => 'Jenis akun tidak cocok dengan arah transaksi.']);
        }

        return $data;
    }
}
