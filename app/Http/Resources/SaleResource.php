<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sale as the mobile client sees it.
 *
 * `is_suspect` is returned but the app deliberately does not act on it: the flag is for
 * Administrator and Finance, and showing the rider "you look suspicious" would be both
 * an accusation and a hint about how to avoid the threshold next time.
 *
 * A voided sale is still returned here, not hidden — the rider's own list is where they
 * see their day, and a transaction that vanished with no trace would look like a bug, not a
 * correction they themselves may have made. Every aggregate that feeds money or stock totals
 * (SalesActivityService, SettlementService, StaffLocationService) excludes it instead; this
 * resource only describes the row.
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
            'is_voided' => $sale->isVoided(),
            'void_reason' => $sale->void_reason,
            'voided_at' => $sale->voided_at?->toIso8601String(),
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
