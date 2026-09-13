<?php

namespace App\Filament\Resources\DirectSales\Pages;

use App\Filament\Resources\DirectSales\DirectSaleResource;
use App\Services\DirectSaleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Routes creation through DirectSaleService::record() instead of a plain Eloquent insert — the
 * stock lock, price pinning and ledger posting all happen there, not on the form. See
 * CreatePurchaseOrder/CreateRecipe for the same established pattern in this codebase.
 */
class CreateDirectSale extends CreateRecord
{
    protected static string $resource = DirectSaleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(DirectSaleService::class)->record(
            actor: Auth::user(),
            cartId: (int) $data['cart_id'],
            lines: $data['lines'] ?? [],
            paymentMethod: $data['payment_method'] ?? 'cash',
            note: $data['note'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
