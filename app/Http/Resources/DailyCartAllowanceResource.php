<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The day's operational allowance for one cart.
 *
 * `is_edited` is exposed because the client shows it differently: an untouched default needs no
 * comment, while a figure someone deliberately changed is worth surfacing to whoever reviews
 * the day.
 */
class DailyCartAllowanceResource extends JsonResource
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
            'amount_minor' => $this->amount_minor,
            'is_edited' => $this->is_edited,
            'set_by' => $this->set_by,
        ];
    }
}
