<?php

namespace App\Http\Controllers\Api;

use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefillRequestRequest;
use App\Http\Resources\RefillRequestResource;
use App\Models\RefillRequest;
use App\Services\RefillRequestStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET/POST /refills` (docs/04 §Flow B). Role scoping (§2.2) is applied at
 * the query level here — never by filtering an already-fetched collection —
 * so a Staff or Barista token can never even receive another cart/kitchen's
 * rows over the wire.
 */
class RefillRequestController extends Controller
{
    private const EAGER = [
        'cart', 'staff', 'kitchen', 'finance', 'barista', 'rider',
        'lines.product', 'evidencePhoto', 'signature',
    ];

    public function __construct(private readonly RefillRequestStateMachine $stateMachine) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = RefillRequest::query()->with(self::EAGER);

        match ($user->role) {
            Role::STAFF => $query->where('staff_id', $user->id),
            Role::BARISTA => $query->where('kitchen_id', $user->kitchen_id),
            Role::RIDER => $this->scopeRider($query, $user->id, (string) $request->query('scope')),
            Role::FINANCE, Role::ADMINISTRATOR => null,
        };

        if ($request->filled('status')) {
            $statuses = collect(explode(',', (string) $request->query('status')))
                ->map(fn ($status) => trim($status))
                ->filter()
                ->values()
                ->all();

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        $refills = $query->orderByDesc('updated_at')->get();

        return RefillRequestResource::collection($refills);
    }

    public function show(Request $request, RefillRequest $refill)
    {
        Gate::authorize('view', $refill);

        $refill->load(array_merge(self::EAGER, [
            'statusHistory' => fn ($query) => $query->orderBy('created_at'),
        ]));

        return new RefillRequestResource($refill);
    }

    public function store(StoreRefillRequestRequest $request)
    {
        Gate::authorize('create', RefillRequest::class);

        $refill = $this->stateMachine->submit(
            $request->user(),
            $request->validated(),
            $request->header('Idempotency-Key'),
        );

        $refill->load(self::EAGER);

        return (new RefillRequestResource($refill))->response()->setStatusCode(201);
    }

    private function scopeRider(Builder $query, int $riderId, string $scope): void
    {
        $query->where(function (Builder $q) use ($riderId, $scope) {
            if ($scope === 'mine') {
                $q->where('rider_id', $riderId);

                return;
            }

            if ($scope === 'pool') {
                $q->whereNull('rider_id')->where('status', RefillStatus::READY_TO_PICK->value);

                return;
            }

            // Default: the unclaimed pool plus this rider's own claims (§2.2, Q4).
            $q->where('rider_id', $riderId)
                ->orWhere(function (Builder $pool) {
                    $pool->whereNull('rider_id')->where('status', RefillStatus::READY_TO_PICK->value);
                });
        });
    }
}
