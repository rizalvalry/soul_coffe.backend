<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartCloseOutRequest;
use App\Http\Requests\StoreShowcaseBrewRequest;
use App\Http\Requests\StoreShowcaseHandoverRequest;
use App\Http\Resources\DailyCartAllowanceResource;
use App\Http\Resources\StockRowResource;
use App\Models\Cart;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\CentralStockService;
use App\Services\DailyAllowanceService;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

/**
 * The showcase flow, barista-side (docs: see CentralStockService).
 *
 * Barista-only throughout: brewing, handing cups to a cart, and sorting what comes back are all
 * kitchen-side acts. Staff read their own cart's stock through `GET /me/stock`, which already
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
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('role:BARISTA'),
        ];
    }

    /** Cups currently sitting in this barista's showcase. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $kitchenId = $this->kitchenIdFor($request->user());

        return StockRowResource::collection(
            $this->allocations->stockRows(StockLedgerService::KITCHEN, $kitchenId)
        );
    }

    /** Cups just brewed — central stock goes up, nothing leaves. */
    public function brew(StoreShowcaseBrewRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        // kitchenIdFor() has already refused the request if this barista has no kitchen, so the
        // relation below is guaranteed present.
        $kitchenId = $this->kitchenIdFor($user);

        try {
            $this->showcase->brewIntoShowcase($user, $user->kitchen, $request->quantities());
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
        $cart = Cart::query()->findOrFail($request->integer('cart_id'));
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
            );
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
        $cart = Cart::query()->findOrFail($request->integer('cart_id'));

        try {
            $this->showcase->closeOutCart(
                $request->user(),
                $cart,
                $request->quantities('returned'),
                $request->quantities('rejected'),
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
                        $this->kitchenIdFor($request->user()),
                    )
                ),
            ],
        ]);
    }

    /** The pre-filled money field, fetched on its own when the form opens. */
    public function allowance(Request $request, Cart $cart): DailyCartAllowanceResource
    {
        return new DailyCartAllowanceResource($this->allowances->forCart($cart));
    }

    /**
     * Staff the barista can hand a cart to.
     *
     * Deliberately NOT `/allocations/today`'s staff-on-shift list, which only returns people who
     * already have an assignment: this form exists precisely for the case where nobody has been
     * assigned yet, so filtering by assignment would hide exactly the staff the barista needs.
     *
     * `assigned_cart_code` is included so the UI can show at a glance who is already placed
     * today — R11 makes picking them a conflict, and saying so in the list is kinder than a 422
     * after they have typed the cups.
     */
    public function staff(Request $request): JsonResponse
    {
        $date = now()->toDateString();

        $staff = User::query()
            ->where('role', Role::STAFF)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone_e164']);

        $assignments = StaffAssignment::query()
            ->whereDate('operating_date', $date)
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
            ])->all(),
        ]);
    }

    /**
     * A barista is wired to exactly one kitchen; without it there is no showcase to speak of.
     */
    private function kitchenIdFor(User $user): int
    {
        if (! $user->kitchen_id) {
            abort(422, 'Akun barista ini belum terhubung ke dapur pusat.');
        }

        return (int) $user->kitchen_id;
    }
}
