<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `GET /carts` (docs/04). No cost fields exist on this model — R15 does not apply here. */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'plate' => $this->plate,
            'status' => $this->status,
            'kitchen_id' => $this->kitchen_id,
        ];
    }
}
