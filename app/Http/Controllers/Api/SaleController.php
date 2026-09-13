<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

/**
 * Sales from a cart, staff-side.
 *
 * Staff-only: these are cups leaving the cart the caller is standing at. A barista or rider has
 * no cart of their own, and an administrator recording somebody else's sale would be inventing
 * revenue.
 *
 * Business-rule failures (not yet absen, no cart today, stock too low) come out of SaleService as
 * RuntimeException and are answered 422 — the caller is allowed to sell in principle, and the
 * message is the useful part. Note what is NOT among them: a large transaction. That is recorded
 * and flagged, never refused.
 */
class SaleController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SaleService $sales) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('role:STAFF', only: ['index', 'store']),
            // Void is reachable by staff (their own sale, inside the window) and by
            // Administrator/Finance (any time) — see SaleService::void() for the actual rule.
            // The role check here only decides who reaches the endpoint at all.
            new Middleware('role:STAFF,ADMINISTRATOR,FINANCE', only: ['void']),
        ];
    }

    /** Today's sales for the caller's own cart — the running list the app shows under the form. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::query()
            ->with(['lines.product:id,name', 'cart:id,code', 'location:id,name'])
            ->where('staff_id', $request->user()->id)
            ->whereDate('operating_date', now()->toDateString())
            ->latest('occurred_at')
            ->get();

        return SaleResource::collection($sales);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        try {
            $sale = $this->sales->record(
                staff: $request->user(),
                lines: $request->validated('lines'),
                uuid: $request->validated('uuid'),
                gps: [
                    'lat' => $request->validated('gps_lat'),
                    'lng' => $request->validated('gps_lng'),
                    'unavailable' => (bool) $request->validated('gps_unavailable', false),
                ],
                paymentMethod: $request->validated('payment_method', 'cash'),
                note: $request->validated('note'),
                deviceId: $request->validated('device_id'),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sale->load(['lines.product:id,name', 'cart:id,code', 'location:id,name']);

        return SaleResource::make($sale)->response()->setStatusCode(201);
    }

    /**
     * Undoes a sale. A staff member may only undo their own, and only inside the configured
     * window; Administrator and Finance are not bound by either — see `SaleService::void()` for
     * the full rule, which is what actually enforces this, not the middleware above.
     */
    public function void(VoidSaleRequest $request, Sale $sale): JsonResponse
    {
        try {
            $voided = $this->sales->void($sale, $request->user(), $request->validated('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $voided->load(['lines.product:id,name', 'cart:id,code', 'location:id,name']);

        return SaleResource::make($voided)->response()->setStatusCode(200);
    }
}
