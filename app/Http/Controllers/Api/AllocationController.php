<?php

namespace App\Http\Controllers\Api;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAllocationRequest;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\StaffOnShiftResource;
use App\Http\Resources\StockRowResource;
use App\Models\Cart;
use App\Models\DailyAllocation;
use App\Models\StaffAssignment;
use App\Services\Access\PermissionMatrix;
use App\Services\AllocationService;
use App\Services\KitchenContext;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Flow A — daily opening allocation (docs/02 §5, docs/04 §Flow A, requirement 1).
 *
 * Role gates are declared here via HasMiddleware rather than in routes/api.php (owned by
 * another workstream): `today`/`store`/`kitchenStock` are barista-only per the contract's
 * "(BARISTA)" heading; `mine`/`myStock` are rider-only. `show` has no route-wide role gate —
 * a Rider caller may view their OWN allocation, which is a per-record check the route-level
 * gate cannot express, so it is delegated to DailyAllocationPolicy instead.
 */
class AllocationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AllocationService $service) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:kitchen_operations,view', only: ['today', 'kitchenStock']),
            new Middleware('permission:kitchen_operations,edit', only: ['store']),
            new Middleware('role:STAFF', only: ['mine', 'myStock']),
        ];
    }

    public function today(Request $request): AnonymousResourceCollection
    {
        $date = $request->query('date', now()->toDateString());
        $kitchenId = KitchenContext::resolve($request);

        return StaffOnShiftResource::collection($this->service->staffOnShift($date, $kitchenId));
    }

    public function store(StoreAllocationRequest $request): JsonResponse
    {
        $kitchenId = KitchenContext::resolve($request, 'edit');
        KitchenContext::assertMatches($kitchenId, Cart::query()->findOrFail($request->integer('cart_id'))->kitchen_id);
        $allocation = $this->service->create($request->validated(), $request->user(), $kitchenId);

        return (new AllocationResource($allocation))->response()->setStatusCode(201);
    }

    public function show(Request $request, DailyAllocation $allocation): AllocationResource
    {
        if ($request->user()->role !== Role::STAFF
            && ($request->user()->role === Role::BARISTA || $request->filled('kitchen_id')
                || ! PermissionMatrix::can($request->user(), PanelModule::ALLOCATIONS, 'view'))) {
            KitchenContext::assertMatches(KitchenContext::resolve($request), $allocation->kitchen_id);
        }
        if ($request->user()->cannot('view', $allocation)) {
            abort(403, 'Anda tidak berhak melihat alokasi ini.');
        }

        return new AllocationResource($allocation->load('lines.product', 'cart', 'staff', 'location'));
    }

    /** The digital *Surat Pengambilan Barang* for the calling rider, today. */
    public function mine(Request $request): JsonResponse
    {
        $allocation = DailyAllocation::query()
            ->where('staff_id', $request->user()->id)
            ->whereDate('operating_date', now()->toDateString())
            ->with('lines.product', 'cart', 'staff', 'location')
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $allocation ? new AllocationResource($allocation) : null,
        ]);
    }

    public function myStock(Request $request): AnonymousResourceCollection
    {
        $assignment = StaffAssignment::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('operating_date', now()->toDateString())
            ->where('source', 'hand_to_cart')
            ->whereNull('superseded_at')
            ->latest('id')
            ->first();

        $rows = $assignment
            ? $this->service->stockRows(StockLedgerService::CART, $assignment->cart_id)
            : [];

        return StockRowResource::collection($rows);
    }

    public function kitchenStock(Request $request): AnonymousResourceCollection
    {
        $kitchenId = KitchenContext::resolve($request);

        $rows = $kitchenId
            ? $this->service->stockRows(StockLedgerService::KITCHEN, $kitchenId)
            : [];

        return StockRowResource::collection($rows);
    }
}
