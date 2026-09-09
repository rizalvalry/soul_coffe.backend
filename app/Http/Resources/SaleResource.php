<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sale as the mobile client sees it.
 *
 * `is_suspect` is returned but the app deliberately does not act on it: the flag is for
 * Administrator and Finance, and showing the staff member "you look suspicious" would be both
 * an accusation and a hint about how to avoid the threshold next time.
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Sale $sale */
        $sale = $this->resource;

        return [
            'id' => $sale->id,
            'uuid' => $sale->uuid,
            'operating_date' => $sale->operating_date->toDateString(),
            'occurred_at' => $sale->occurred_at->toIso8601String(),
            'cart_code' => $sale->cart?->code,
            'location_name' => $sale->location?->name,
            'total_qty' => $sale->total_qty,
            'total_amount' => $sale->total_amount_minor,
            'payment_method' => $sale->payment_method,
            'note' => $sale->note,
            'lines' => $sale->lines->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'qty' => $line->qty,
                'unit_price' => $line->unit_price_minor,
                'subtotal' => $line->subtotal_minor,
            ])->all(),
        ];
    }
}
