<?php

namespace App\Filament\Resources\CashCategories\Pages;

use App\Filament\Resources\CashCategories\CashCategoryResource;
use App\Models\Account;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCashCategory extends EditRecord
{
    protected static string $resource = CashCategoryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->transactions()->exists()
            && ($this->record->direction !== $data['direction'] || $this->record->account_id !== (int) $data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Arah dan akun kategori yang sudah dipakai tidak dapat diubah.']);
        }
        $type = Account::query()->whereKey($data['account_id'])->where('is_active', true)->value('type');
        if (! in_array($type, $data['direction'] === 'OUT' ? ['EXPENSE', 'ASSET'] : ['REVENUE', 'EQUITY'], true)) {
            throw ValidationException::withMessages(['account_id' => 'Jenis akun tidak cocok dengan arah transaksi.']);
        }

        return $data;
    }
}
