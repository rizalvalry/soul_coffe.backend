<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Routes creation through PurchaseOrderService::create() — status, uuid and the DRAFT lifecycle
 * are decided there, not typed on the form, and the service is what enforces "at least one line,
 * positive qty/cost, no duplicate raw material".
 */
class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PurchaseOrderService::class)->create(
            supplierId: (int) $data['supplier_id'],
            kitchenId: (int) $data['kitchen_id'],
            lines: $data['lines'] ?? [],
            actor: Auth::user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
