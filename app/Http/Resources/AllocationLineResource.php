<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a `daily_allocations` row, nested under AllocationResource. No cost fields
 * exist on this model — R15 does not apply here (Flow A carries no money).
 */
class AllocationLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'target_qty' => $this->target_qty,
            'qty_issued' => $this->qty_issued,
        ];
    }
}
