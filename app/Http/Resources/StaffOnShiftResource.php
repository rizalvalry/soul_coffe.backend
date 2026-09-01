<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /allocations/today` row shape (docs/04 §Flow A). The resource is a pass-through over
 * an already-shaped associative array built by AllocationService::staffOnShift() — the shaping
 * (joining staff_assignments + daily_targets + "has this cart already been allocated today")
 * is business logic that belongs in the service, not here.
 */
class StaffOnShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'staff_id' => $this->resource['staff_id'],
            'staff_name' => $this->resource['staff_name'],
            'cart_id' => $this->resource['cart_id'],
            'cart_code' => $this->resource['cart_code'],
            'location_id' => $this->resource['location_id'],
            'location_name' => $this->resource['location_name'],
            'has_allocation' => $this->resource['has_allocation'],
            'targets' => $this->resource['targets'],
        ];
    }
}
