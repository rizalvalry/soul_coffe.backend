<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\RefillRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * R15 / docs/04 §Conventions: `unit_cost` and `line_cost` are omitted
 * entirely (not null, not zero) for any viewer that is not FINANCE or
 * ADMINISTRATOR.
 *
 * @mixin RefillRequestLine
 */
class RefillLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'qty_requested' => $this->qty_requested,
            'qty_approved' => $this->qty_approved,
            'qty_prepared' => $this->qty_prepared,
            'qty_received' => $this->qty_received,
        ];

        $viewer = $request->user();

        if ($viewer && in_array($viewer->role, [Role::FINANCE, Role::ADMINISTRATOR], true)) {
            $data['unit_cost'] = $this->unit_cost_minor;
            $data['line_cost'] = $this->line_cost_minor;
        }

        return $data;
    }
}
