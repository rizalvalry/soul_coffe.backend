<?php

namespace App\Http\Resources;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class KitchenRejectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kitchen_id' => $this->kitchen_id,
            'kitchen_name' => $this->kitchen->name,
            'actor_name' => $this->actor->name,
            'operating_date' => $this->operating_date->toDateString(),
            'reason_code' => $this->reason_code,
            'note' => $this->note,
            'status' => $this->status,
            'photo_url' => Storage::disk('public')->url($this->photo->path),
            'lines' => $this->lines->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'qty' => $line->qty,
                'cost_minor' => PermissionMatrix::can($request->user(), PanelModule::INVENTORY_COSTS, 'view')
                    ? $line->cost_minor : null,
            ])->all(),
            'correction_reason' => $this->correction_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
