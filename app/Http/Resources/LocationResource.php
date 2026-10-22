<?php

namespace App\Http\Resources;

use App\Services\LocationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `GET /locations` (docs/04). */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'geofence_m' => app(LocationSettings::class)->radiusFor($this->resource),
            'notes' => $this->notes,
        ];
    }
}
