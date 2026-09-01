<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Allocation` — `POST /allocations`, `GET /allocations/{id}`, `GET /me/allocation/today`
 * (docs/04 §Flow A). Callers should eager-load `lines.product`, `cart`, `staff`, `location`
 * before wrapping, or the related *_name fields resolve to null instead of issuing N+1 queries.
 */
class AllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_date' => $this->operating_date?->toDateString(),
            'cart_id' => $this->cart_id,
            'cart_code' => $this->whenLoaded('cart', fn () => $this->cart?->code),
            'staff_id' => $this->staff_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->name),
            'kitchen_id' => $this->kitchen_id,
            'barista_id' => $this->barista_id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),
            'status' => $this->status?->value,
            'is_correction' => $this->is_correction,
            'correction_reason' => $this->correction_reason,
            'over_target_pct' => $this->over_target_pct,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'lines' => AllocationLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
