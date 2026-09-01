<?php

namespace App\Http\Resources;

use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\RefillRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * `RefillRequest` shape (docs/04). Two rules make this resource load-bearing
 * rather than a plain transform:
 *
 * R15 — `total_cost` (and, via RefillLineResource, `unit_cost`/`line_cost`)
 * is omitted entirely — not null, not zero — unless the viewer is FINANCE or
 * ADMINISTRATOR. Filtering happens here at serialisation, never left to the
 * client to hide.
 *
 * The `can` object is server-computed from RefillRequestPolicy (who) plus
 * the current status (when), so the client renders actions from data instead
 * of re-deriving permission itself — a hidden button is not a permission.
 *
 * @mixin RefillRequest
 */
class RefillRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canSeeCost = $viewer && in_array($viewer->role, [Role::FINANCE, Role::ADMINISTRATOR], true);

        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'operating_date' => $this->operating_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'version' => $this->version,

            'cart' => $this->whenLoaded('cart', fn () => [
                'id' => $this->cart->id,
                'code' => $this->cart->code,
            ]),
            'staff' => $this->whenLoaded('staff', fn () => [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ]),
            'kitchen' => $this->whenLoaded('kitchen', fn () => [
                'id' => $this->kitchen->id,
                'name' => $this->kitchen->name,
            ]),
            'finance' => $this->whenLoaded('finance', fn () => $this->finance ? [
                'id' => $this->finance->id,
                'name' => $this->finance->name,
            ] : null),
            'barista' => $this->whenLoaded('barista', fn () => $this->barista ? [
                'id' => $this->barista->id,
                'name' => $this->barista->name,
            ] : null),
            'rider' => $this->whenLoaded('rider', fn () => $this->rider ? [
                'id' => $this->rider->id,
                'name' => $this->rider->name,
            ] : null),

            'evidence_photo_url' => $this->whenLoaded(
                'evidencePhoto',
                fn () => $this->evidencePhoto ? Storage::disk('public')->url($this->evidencePhoto->path) : null,
            ),
            'signature_url' => $this->whenLoaded(
                'signature',
                fn () => $this->signature ? Storage::disk('public')->url($this->signature->path) : null,
            ),
            'signature_method' => $this->signature_method?->value,

            'gps_lat' => $this->gps_lat !== null ? (float) $this->gps_lat : null,
            'gps_lng' => $this->gps_lng !== null ? (float) $this->gps_lng : null,
            'gps_unavailable' => $this->gps_unavailable,
            'out_of_hours' => $this->out_of_hours,

            'decision_reason' => $this->decision_reason,
            'shortfall_reason' => $this->shortfall_reason,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'prepared_at' => $this->prepared_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),

            'lines' => RefillLineResource::collection($this->whenLoaded('lines')),

            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'from_status' => $h->from_status?->value,
                'to_status' => $h->to_status->value,
                'actor_id' => $h->actor_id,
                'actor_role' => $h->actor_role,
                'reason' => $h->reason,
                'device_id' => $h->device_id,
                'gps_lat' => $h->gps_lat !== null ? (float) $h->gps_lat : null,
                'gps_lng' => $h->gps_lng !== null ? (float) $h->gps_lng : null,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->all()),

            'can' => $this->computeCan($viewer),
        ];

        if ($canSeeCost) {
            $data['total_cost'] = $this->total_cost_minor;
        }

        return $data;
    }

    /**
     * @return array<string, bool>
     */
    private function computeCan(?User $viewer): array
    {
        if (! $viewer) {
            return [
                'approve' => false,
                'reject' => false,
                'cancel' => false,
                'start_preparing' => false,
                'mark_ready' => false,
                'claim' => false,
                'deliver' => false,
            ];
        }

        /** @var RefillRequest $refill */
        $refill = $this->resource;
        $status = $refill->status;

        return [
            'approve' => $viewer->can('approve', $refill) && $status === RefillStatus::SUBMITTED,
            'reject' => $viewer->can('reject', $refill) && $status === RefillStatus::SUBMITTED,
            'cancel' => $viewer->can('cancel', $refill) && $status === RefillStatus::SUBMITTED,
            'start_preparing' => $viewer->can('startPreparing', $refill) && $status === RefillStatus::APPROVED,
            'mark_ready' => $viewer->can('markReady', $refill) && $status === RefillStatus::PREPARING,
            'claim' => $viewer->can('claim', $refill) && $status === RefillStatus::READY_TO_PICK && $refill->rider_id === null,
            'deliver' => $viewer->can('deliver', $refill) && $status === RefillStatus::PICKED_UP,
        ];
    }
}
