<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One clock-in row. `role` is the stored value, not a live join — see the attendances migration.
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_date' => $this->operating_date?->toDateString(),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'role' => $this->role?->value,
            'clocked_in_at' => $this->clocked_in_at?->toIso8601String(),
        ];
    }
}
