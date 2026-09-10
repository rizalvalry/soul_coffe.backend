<?php

namespace App\Http\Resources;

use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One deposit, as Finance sees it on the phone.
 *
 * Money is whole rupiah (R9), so every amount here is an integer and the client formats it. The
 * variance is signed on purpose: "lebih 20.000" and "kurang 20.000" are different conversations.
 */
class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Settlement $settlement */
        $settlement = $this->resource;

        return [
            'id' => $settlement->id,
            'operating_date' => $settlement->operating_date?->toDateString(),
            'cart_id' => $settlement->cart_id,
            'cart_code' => $settlement->cart?->code,
            'staff_id' => $settlement->staff_id,
            'staff_name' => $settlement->staff?->name,
            'status' => $settlement->status,
            'cash' => $settlement->cash_minor,
            'qris' => $settlement->qris_minor,
            'transfer' => $settlement->transfer_minor,
            'declared_total' => $settlement->declared_total_minor,
            'expected_total' => $settlement->expected_total_minor,
            'variance' => $settlement->variance_minor,
            'variance_reason' => $settlement->variance_reason,
            'reconciled_by' => $settlement->reconciledBy?->name,
            'reconciled_at' => $settlement->reconciled_at?->toIso8601String(),
            'lines' => $settlement->lines->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'qty_issued' => $line->qty_issued,
                'qty_sold' => $line->qty_sold,
                'qty_remaining' => $line->qty_remaining,
                'qty_wasted' => $line->qty_wasted,
                'variance_qty' => $line->variance_qty,
            ])->all(),
        ];
    }
}
