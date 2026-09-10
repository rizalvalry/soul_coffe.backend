<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Models\Cart;
use App\Models\Settlement;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Setoran — Finance receiving the day's money and settling the cups that came back.
 *
 * FINANCE AND ADMINISTRATOR ONLY. This is the money desk: the person receiving a deposit is the
 * one recording it, and a staff member recording their own would be the whole control gone.
 *
 * Four endpoints, matching the four things that happen at the desk:
 *   queue    — who is still waiting to deposit;
 *   draft    — what the system already knows about this cart's day, so nothing gets recited;
 *   store    — the money, taken while the person is standing there;
 *   approve  — the cups afterwards: back to the showcase, or thrown away.
 */
class SettlementController extends Controller implements HasMiddleware
{
    private const EAGER = ['cart:id,code', 'staff:id,name', 'reconciledBy:id,name', 'lines.product:id,name'];

    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('role:FINANCE,ADMINISTRATOR'),
        ];
    }

    /** The queue of carts to collect from today, unpaid ones first. */
    public function queue(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->settlements->queue($this->date($request)),
        ]);
    }

    /** Everything the deposit form should already know for one cart. */
    public function draft(Request $request, Cart $cart): JsonResponse
    {
        return response()->json([
            'data' => $this->settlements->draft($cart, $this->date($request)),
        ]);
    }

    public function index(Request $request)
    {
        $settlements = Settlement::query()
            ->with(self::EAGER)
            ->whereDate('operating_date', $this->date($request)->toDateString())
            ->latest('id')
            ->get();

        return SettlementResource::collection($settlements);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            // Whole rupiah (R9). No floats anywhere near money.
            'cash' => ['required', 'integer', 'min:0'],
            'qris' => ['required', 'integer', 'min:0'],
            'transfer' => ['required', 'integer', 'min:0'],
            // Required by the service when the totals disagree; optional here because whether
            // they disagree is the service's arithmetic, not the client's.
            'variance_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cart = Cart::query()->findOrFail($data['cart_id']);

        try {
            $settlement = $this->settlements->record(
                cart: $cart,
                finance: $request->user(),
                money: [
                    'cash' => (int) $data['cash'],
                    'qris' => (int) $data['qris'],
                    'transfer' => (int) $data['transfer'],
                ],
                varianceReason: $data['variance_reason'] ?? null,
                date: $this->date($request),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return SettlementResource::make($settlement->load(self::EAGER))->response()->setStatusCode(201);
    }

    /**
     * Approve, and say what happens to the cups still on the cart.
     *
     * The disposition may legitimately be empty — a cart that sold out has nothing to settle, and
     * so does one a barista already closed out.
     */
    public function approve(Request $request, Settlement $settlement): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_returned' => ['required', 'integer', 'min:0', 'max:9999'],
            'lines.*.qty_rejected' => ['required', 'integer', 'min:0', 'max:9999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $approved = $this->settlements->approve(
                settlement: $settlement,
                finance: $request->user(),
                disposition: $data['lines'] ?? [],
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return SettlementResource::make($approved->load(self::EAGER))->response()->setStatusCode(200);
    }

    private function date(Request $request): Carbon
    {
        try {
            return Carbon::parse((string) $request->query('date', now()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }
}
