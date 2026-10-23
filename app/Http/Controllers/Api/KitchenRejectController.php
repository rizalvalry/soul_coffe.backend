<?php

namespace App\Http\Controllers\Api;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKitchenRejectRequest;
use App\Http\Resources\KitchenRejectResource;
use App\Models\CentralKitchen;
use App\Models\KitchenReject;
use App\Services\Access\PermissionMatrix;
use App\Services\AllocationService;
use App\Services\KitchenContext;
use App\Services\KitchenRejectService;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class KitchenRejectController extends Controller
{
    private const WITH = ['lines.product', 'kitchen', 'actor', 'photo'];

    public function __construct(private readonly KitchenRejectService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $kitchenId = KitchenContext::resolve($request, 'view', PanelModule::KITCHEN_REJECT);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? today()->toDateString();

        return KitchenRejectResource::collection(KitchenReject::query()->with(self::WITH)
            ->where('kitchen_id', $kitchenId)->where('operating_date', $date)
            ->latest('id')->limit(100)->get());
    }

    public function kitchens(Request $request): JsonResponse
    {
        abort_unless(PermissionMatrix::can($request->user(), PanelModule::KITCHEN_REJECT, 'view')
            || PermissionMatrix::can($request->user(), PanelModule::KITCHEN_REJECT, 'create'), 403);

        return response()->json(['data' => CentralKitchen::query()->where('is_active', true)
            ->when($request->user()->role === Role::BARISTA,
                fn ($query) => $query->whereKey($request->user()->kitchen_id))
            ->orderBy('name')->get(['id', 'name'])]);
    }

    public function stock(Request $request, AllocationService $allocations): JsonResponse
    {
        $kitchenId = KitchenContext::resolve($request, 'create', PanelModule::KITCHEN_REJECT);
        $rows = $allocations->stockRows(StockLedgerService::KITCHEN, $kitchenId);

        return response()->json(['data' => $rows]);
    }

    public function store(StoreKitchenRejectRequest $request): JsonResponse
    {
        $kitchenId = KitchenContext::resolve($request, 'create', PanelModule::KITCHEN_REJECT);
        $reject = $this->service->record($request->user(), $kitchenId,
            $request->validated('lines'), $request->validated('reason_code'),
            $request->validated('note'), $request->file('photo'),
            Carbon::parse($request->validated('photo_taken_at')));

        return KitchenRejectResource::make($reject)->response()->setStatusCode(201);
    }

    public function review(Request $request, KitchenReject $reject): KitchenRejectResource
    {
        KitchenContext::authorize($request->user(), 'edit', PanelModule::KITCHEN_REJECT);

        return KitchenRejectResource::make($this->service->review($reject, $request->user()));
    }

    public function correct(Request $request, KitchenReject $reject): KitchenRejectResource
    {
        KitchenContext::authorize($request->user(), 'edit', PanelModule::KITCHEN_REJECT);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return KitchenRejectResource::make($this->service->correct($reject, $request->user(), $data['reason']));
    }
}
