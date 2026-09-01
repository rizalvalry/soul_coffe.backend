<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `CartStockRow` — `GET /me/stock`, `GET /kitchen/stock` (docs/04). A pass-through over the
 * shaped array built by AllocationService::stockRows(), itself a projection over
 * StockLedgerService::stockMap() (R6 — stock is never read from anywhere else).
 */
class StockRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->resource['product_id'],
            'product_name' => $this->resource['product_name'],
            'qty' => $this->resource['qty'],
        ];
    }
}
