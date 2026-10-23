<?php

namespace App\Http\Controllers\Api;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartCloseOutRequest;
use App\Http\Requests\StoreShowcaseBrewRequest;
use App\Http\Requests\StoreShowcaseHandoverRequest;
use App\Http\Resources\DailyCartAllowanceResource;
use App\Http\Resources\StockRowResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\RiderClosing;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AllocationService;
use App\Services\CentralStockService;
use App\Services\DailyAllowanceService;
use App\Services\KitchenContext;
use App\Services\ProductionService;
use App\Services\StockLedgerService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

/**
 * The showcase flow (docs: see CentralStockService).
 *
 * Produksi brews and hands cups to a cart; Runner sorts the returned cups at close-out.
 * Rider reads their own cart's stock through `GET /me/stock`, which already
 * exists and already reads the same ledger, so nothing here is duplicated for them.
 *
 * Business-rule failures come out of the service as RuntimeException and are translated to 422
 * here. They are 422 and not 403 because the caller IS allowed to do this in principle — the
 * showcase is short, or the cart is already taken — and the message is the useful part.
 */
class ShowcaseStockController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CentralStockService $showcase,
        private readonly AllocationService $allocations,
        private readonly DailyAllowanceService $allowances,
        private readonly ProductionService $production,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:kitchen_operations,view', only: [
                'index', 'allowance', 'staff',
            ]),
            new Middleware('permission:kitchen_operations,edit', only: ['brew', 'handToCart']),
            new Middleware('permission:cart_close_out,view', only: ['closeOutDraft']),
            new Middleware('permission:cart_close_out,edit', only: ['closeOut']),
        ];
    }

    /**
     * @return array<int, Middleware>
     */
    public function kitchens(Request $request): JsonResponse
    {
        abort_unless(PermissionMatrix::can($request->user(), PanelModule::KITCHEN_OPERATIONS, 'view')
            || PermissionMatrix::can($request->user(), PanelModule::CART_CLOSE_OUT, 'view'), 403);

        return response()->json(['data' => CentralKitchen::query()->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])]);
    }

    /** Cups currently sitting in this barista's showcase. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $kitchenId = KitchenContext::resolve($request);

        return StockRowResource::collection(
            $this->allocations->stockRows(StockLedgerService::KITCHEN, $kitchenId)
        );
    }

    /**
     * Cups just brewed — central stock goes up, nothing leaves. Since Phase 2 this also consumes
     * whatever raw materials the brewed products' recipes call for (ProductionService); the
     * request and response contract here is unchanged.
     */
    public function brew(StoreShowcaseBrewRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        // kitchenIdFor() has already refused the request if this barista has no kitchen, so the
        // relation below is guaranteed present.
        $kitchenId = KitchenContext::resolve($request, 'edit');

        try {
            $this->production->brew($kitchenId, $request->quantities(), $user);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        // Returns the resulting showcase so the client doesn't need a second call to refresh.
        return StockRowResource::collection(
            $this->allocations->stockRows(StockLedgerService::KITCHEN, $kitchenId)
        );
    }

    /**
     * The Add Stock submit: cups move showcase -> cart, the day's money is recorded, and the
     * cart lands on today's roster as a result.
     */
    public function handToCart(StoreShowcaseHandoverRequest $request): JsonResponse
    {
        $kitchenId = KitchenContext::resolve($request, 'edit');
        $cart = Cart::query()->findOrFail($request->integer('cart_id'));
        KitchenContext::assertMatches($kitchenId, $cart->kitchen_id);
        $staff = User::query()->findOrFail($request->integer('staff_id'));

        try {
            $assignment = $this->showcase->handToCart(
                $request->user(),
                $cart,
                $staff,
                $request->quantities(),
                $request->filled('allowance_amount') ? $request->integer('allowance_amount') : null,
                $request->filled('operating_date') ? $request->date('operating_date') : null,
                $request->filled('location_id') ? $request->integer('location_id') : null,
                $kitchenId,
            );
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $allowance = $this->allowances->forCart($cart, $assignment->operating_date);

        return response()->json([
            'data' => [
                'assignment_id' => $assignment->id,
                'cart_id' => $cart->id,
                'cart_code' => $cart->code,
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
                'operating_date' => $assignment->operating_date?->toDateString(),
                'allowance' => new DailyCartAllowanceResource($allowance),
                'cart_stock' => StockRowResource::collection(
                    $this->allocations->stockRows(StockLedgerService::CART, $cart->id)
                ),
                'showcase_stock' => StockRowResource::collection(
                    $this->allocations->stockRows(StockLedgerService::KITCHEN, $assignment->kitchen_id)
                ),
            ],
        ], 201);
    }

    /** End of day: unsold cups back to the showcase, or written off. */
    public function closeOut(StoreCartCloseOutRequest $request): JsonResponse
    {
        $kitchenId = KitchenContext::resolve($request, 'edit', PanelModule::CART_CLOSE_OUT);
        $cart = Cart::query()->findOrFail($request->integer('cart_id'));
        KitchenContext::assertMatches($kitchenId, $cart->kitchen_id);

        try {
            $this->showcase->closeOutCart(
                $request->user(),
                $cart,
                $request->quantities('returned'),
                $request->quantities('rejected'),
                $kitchenId,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => [
                'cart_id' => $cart->id,
                'cart_code' => $cart->code,
                'cart_stock' => StockRowResource::collection(
                    $this->allocations->stockRows(StockLedgerService::CART, $cart->id)
                ),
                'showcase_stock' => StockRowResource::collection(
                    $this->allocations->stockRows(
                        StockLedgerService::KITCHEN,
                        $kitchenId,
                    )
                ),
            ],
        ]);
    }

    public function closeOutDraft(Request $request, Cart $cart): JsonResponse
    {
        KitchenContext::assertCartMatches(KitchenContext::resolve($request, 'view', PanelModule::CART_CLOSE_OUT), $cart);
        $closing = RiderClosing::query()->with('lines')->where('cart_id', $cart->id)
            ->whereDate('operating_date', today()->toDateString())->first();
        $liveStock = $this->allocations->stockRows(StockLedgerService::CART, $cart->id);
        $liveByProduct = collect($liveStock)->keyBy('product_id');

        return response()->json(['data' => [
            'cart_id' => $cart->id,
            'closing_id' => $closing?->id,
            'lines' => $closing?->lines->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'qty_remaining' => $line->qty_remaining,
                'qty_wasted' => $line->qty_wasted,
                'qty_to_return' => min($line->qty_remaining, (int) ($liveByProduct->get($line->product_id)['qty'] ?? 0)),
            ])->values()->all() ?? [],
        ]]);
    }

    /** The pre-filled money field, fetched on its own when the form opens. */
    public function allowance(Request $request, Cart $cart): DailyCartAllowanceResource
    {
        KitchenContext::assertCartMatches(KitchenContext::resolve($request), $cart);

        return new DailyCartAllowanceResource($this->allowances->forCart($cart));
    }

    /**
     * Rider the barista can hand a cart to.
     *
     * Deliberately NOT `/allocations/today`'s rider-on-shift list, which only returns people who
     * already have an assignment: this form exists precisely for the case where nobody has been
     * assigned yet, so filtering by assignment would hide exactly the rider the barista needs.
     *
     * `assigned_cart_code` is included so the UI can show at a glance who is already placed
     * today — R11 makes picking them a conflict, and saying so in the list is kinder than a 422
     * after they have typed the cups.
     */
    public function staff(Request $request): JsonResponse
    {
        KitchenContext::resolve($request);
        $date = now()->toDateString();

        $staff = User::query()
            ->where('role', Role::STAFF)
            ->where('is_active', true)
            ->orderBy('name')
            ->with(['locationSetups' => fn ($query) => $query->whereNull('superseded_at')->with('location:id,name')->latest('id')])
            ->get(['id', 'name', 'phone_e164']);

        $assignments = StaffAssignment::query()
            ->whereDate('operating_date', $date)
            ->where('source', 'hand_to_cart')
            ->whereNull('superseded_at')
            ->with('cart:id,code')
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'data' => $staff->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone_e164,
                'assigned_cart_id' => $assignments->get($user->id)?->cart_id,
                'assigned_cart_code' => $assignments->get($user->id)?->cart?->code,
                'location_setup' => $user->locationSetups->first() ? [
                    'location_id' => $user->locationSetups->first()->location_id,
                    'location_name' => $user->locationSetups->first()->location?->name,
                    'basis' => $user->locationSetups->first()->basis,
                ] : null,
            ])->all(),
        ]);
    }
}
