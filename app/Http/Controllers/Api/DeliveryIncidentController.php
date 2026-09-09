<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryIncidentRequest;
use App\Http\Resources\DeliveryIncidentResource;
use App\Models\DeliveryIncident;
use App\Models\RefillRequest;
use App\Services\DeliveryIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Cups damaged in transit: the rider's report, and Finance's decision about it.
 *
 * Authorisation is per action rather than per controller, because the two acts belong to
 * different people by design — see DeliveryIncidentService. A rider may report and may read
 * their own reports; only Finance or an Administrator may decide.
 */
class DeliveryIncidentController extends Controller
{
    private const EAGER = [
        'lines.product:id,name',
        'photo',
        'rider:id,name',
        'refillRequest:id,code,cart_id,staff_id,kitchen_id,status',
        'refillRequest.cart:id,code',
        'decidedBy:id,name',
    ];

    public function __construct(private readonly DeliveryIncidentService $incidents) {}

    /**
     * What the caller is entitled to see.
     *
     * Scoped at the QUERY, never by filtering a response (docs/02 §2.1): a rider sees their own
     * reports, a staff member the ones on their own deliveries, and Finance/Administrator all of
     * them. Anyone else gets an empty list rather than a 403 — there is nothing secret about the
     * existence of the endpoint, only about the rows.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = DeliveryIncident::query()->with(self::EAGER)->latest('reported_at');

        if (! in_array($user->role, [Role::FINANCE, Role::ADMINISTRATOR], true)) {
            $query->where(function ($scoped) use ($user): void {
                $scoped->where('rider_id', $user->id)
                    ->orWhereHas('refillRequest', fn ($refill) => $refill->where('staff_id', $user->id));
            });
        }

        if ($request->boolean('open')) {
            $query->where('status', IncidentStatus::REPORTED);
        }

        return DeliveryIncidentResource::collection($query->limit(100)->get());
    }

    public function store(StoreDeliveryIncidentRequest $request, RefillRequest $refill): JsonResponse
    {
        if ($request->user()->role !== Role::RIDER) {
            abort(403, 'Hanya rider yang melaporkan insiden pengiriman.');
        }

        $incident = $this->incidents->report(
            refill: $refill,
            rider: $request->user(),
            lines: $request->validated('lines'),
            photo: $request->file('photo'),
            photoTakenAt: Carbon::parse($request->validated('photo_taken_at')),
            uuid: $request->validated('uuid'),
            note: $request->validated('note'),
            deviceId: $request->validated('device_id'),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        return DeliveryIncidentResource::make($incident->load(self::EAGER))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The decision. Two outcomes, because there are two things that can happen to a bike with
     * broken cups on it: it comes back, or it carries on with what survived.
     */
    public function resolve(Request $request, DeliveryIncident $incident): DeliveryIncidentResource
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['cancel', 'partial'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $resolved = $this->incidents->resolve(
            incident: $incident,
            decider: $request->user(),
            mode: $data['mode'],
            note: $data['note'] ?? null,
        );

        return DeliveryIncidentResource::make($resolved->load(self::EAGER));
    }
}
