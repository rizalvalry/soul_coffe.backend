<?php

namespace App\Http\Resources;

use App\Models\DeliveryIncident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One incident as the app sees it.
 *
 * Carries the decision as well as the report, because the rider who filed it needs to know what
 * was decided — that is the whole reason they are still standing next to their bike waiting.
 */
class DeliveryIncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryIncident $incident */
        $incident = $this->resource;

        return [
            'id' => $incident->id,
            'uuid' => $incident->uuid,
            'refill_request_id' => $incident->refill_request_id,
            'refill_code' => $incident->refillRequest?->code,
            'cart_code' => $incident->refillRequest?->cart?->code,
            'rider_name' => $incident->rider?->name,
            'reported_at' => $incident->reported_at?->toIso8601String(),
            'note' => $incident->note,
            'status' => $incident->status->value,
            'status_label' => $incident->status->label(),
            'photo_url' => $incident->photo
                ? $this->absoluteUrl(Storage::disk('public')->url($incident->photo->path))
                : null,
            'damaged_qty' => (int) $incident->lines->sum('qty_damaged'),
            'written_off_qty' => $incident->written_off_qty,
            'decided_by' => $incident->decidedBy?->name,
            'decided_at' => $incident->decided_at?->toIso8601String(),
            'decision_note' => $incident->decision_note,
            'lines' => $incident->lines->map(fn ($line): array => [
                'line_id' => $line->refill_request_line_id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'qty_damaged' => $line->qty_damaged,
            ])->all(),
        ];
    }

    /**
     * The app resolves URLs against its own bundle, so a root-relative path from a `public` disk
     * with no configured `url` would be a broken image — same normalisation as ProductResource.
     */
    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }
}
