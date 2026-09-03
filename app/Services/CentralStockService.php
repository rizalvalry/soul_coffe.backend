<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DailyCartAllowance;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The "showcase" flow: coffee brewed into central stock, then handed out to carts.
 *
 * Two movements, both performed by a Barista from the app:
 *
 *   brewIntoShowcase()  kitchen stock goes UP    (PRODUCTION_IN)
 *   handToCart()        kitchen DOWN, cart UP    (ALLOCATION_OUT / ALLOCATION_IN)
 *
 * This sits deliberately alongside Flow A (`AllocationService`) and Flow B
 * (`RefillRequestStateMachine`) rather than replacing either — a product decision, so the
 * approval-gated paths keep working exactly as before for anyone still using them.
 *
 * ONE INVARIANT IS DELIBERATELY NOT ENFORCED HERE. Flow A escalates to PENDING_FINANCE when a
 * morning allocation runs more than `soul.allocation_over_target_tolerance` above DailyTarget,
 * specifically to stop over-allocation being used to dodge Finance approval. `handToCart()` has
 * no such gate: it was asked for as a barista-trusted path where the barista just types cups and
 * submits. That is a decision about trust, not an oversight — but it does mean this path can
 * put more cups on a cart than Flow A would have allowed without anyone approving it. Everything
 * still lands in `stock_ledger` and `audit_log`, so it is reviewable after the fact; it is simply
 * not stoppable before it. If that trade stops being acceptable, the gate belongs here, in
 * handToCart(), mirroring AllocationService::create().
 */
class CentralStockService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly DailyAllowanceService $allowances,
    ) {}

    /**
     * Barista brews cups into the kitchen's showcase — central/main stock goes up.
     *
     * @param  array<int,int>  $qtyByProductId  product_id => cups brewed
     */
    public function brewIntoShowcase(
        User $barista,
        CentralKitchen $kitchen,
        array $qtyByProductId,
    ): void {
        $this->assertBarista($barista);
        $rows = $this->normalizeQuantities($qtyByProductId);

        DB::transaction(function () use ($barista, $kitchen, $rows): void {
            foreach ($rows as $productId => $qty) {
                $this->ledger->post(
                    StockLedgerService::KITCHEN,
                    $kitchen->id,
                    $productId,
                    MovementType::PRODUCTION_IN,
                    $qty,
                    $barista->id,
                    $kitchen->id,
                    'showcase_brew',
                    null,
                );
            }

            $this->audit($barista, 'central_stock.brew', $kitchen->id, [
                'kitchen_id' => $kitchen->id,
                'quantities' => $rows,
            ]);
        });
    }

    /**
     * Barista moves cups out of the showcase onto one cart, and records the day's allowance.
     *
     * Creating the StaffAssignment here is the point, not a side effect: the roster used to be
     * something an admin had to fill in ahead of time, and a cart with no assignment row could
     * not be sold from at all. Handing that cart its cups IS the act that puts it on today's
     * roster, so the barista never has to maintain a separate list, and no cart can be blocked
     * from selling by paperwork nobody did.
     *
     * @param  array<int,int>  $qtyByProductId  product_id => cups handed over
     */
    public function handToCart(
        User $barista,
        Cart $cart,
        User $staff,
        array $qtyByProductId,
        ?int $allowanceAmount = null,
        ?Carbon $operatingDate = null,
        ?int $locationId = null,
    ): StaffAssignment {
        $this->assertBarista($barista);

        if ($staff->role !== Role::STAFF) {
            throw new RuntimeException('Penerima gerobak harus staff.');
        }

        $rows = $this->normalizeQuantities($qtyByProductId);
        $date = ($operatingDate ?? Carbon::today());
        $kitchenId = $cart->kitchen_id ?? $barista->kitchen_id;

        if (! $kitchenId) {
            throw new RuntimeException('Gerobak ini belum terhubung ke dapur pusat.');
        }

        return DB::transaction(function () use (
            $barista, $cart, $staff, $rows, $allowanceAmount, $date, $kitchenId, $locationId
        ): StaffAssignment {
            // Sufficiency is checked against LOCKED rows, in product order, so two baristas
            // handing out the same product at the same moment cannot both pass the check and
            // drive the showcase negative (the same guard AllocationService uses).
            $locked = $this->ledger->lockAndProject(
                StockLedgerService::KITCHEN,
                $kitchenId,
                array_keys($rows),
            );

            $shortages = [];
            foreach ($rows as $productId => $qty) {
                $available = $locked[$productId] ?? 0;
                if ($available < $qty) {
                    $shortages[] = "produk #{$productId} (butuh {$qty}, tersedia {$available})";
                }
            }

            if ($shortages !== []) {
                throw new RuntimeException(
                    'Stok showcase tidak cukup untuk: '.implode(', ', $shortages)
                );
            }

            $assignment = $this->ensureAssignment($barista, $cart, $staff, $date, $kitchenId, $locationId);

            foreach ($rows as $productId => $qty) {
                $this->ledger->transfer(
                    StockLedgerService::KITCHEN,
                    $kitchenId,
                    StockLedgerService::CART,
                    $cart->id,
                    $productId,
                    MovementType::ALLOCATION_OUT,
                    MovementType::ALLOCATION_IN,
                    $qty,
                    $barista->id,
                    $kitchenId,
                    'showcase_handover',
                    $assignment->id,
                );
            }

            $allowance = $allowanceAmount === null
                ? $this->allowances->forCart($cart, $date)
                : $this->allowances->override($cart, $allowanceAmount, $barista, $date);

            $this->audit($barista, 'central_stock.hand_to_cart', $assignment->id, [
                'cart_id' => $cart->id,
                'staff_id' => $staff->id,
                'operating_date' => $date->toDateString(),
                'quantities' => $rows,
                'allowance_minor' => $allowance->amount_minor,
                'allowance_edited' => $allowance->is_edited,
            ]);

            return $assignment;
        });
    }

    /**
     * End of day: unsold cups either go back to the showcase to sell tomorrow, or are written
     * off as rejects.
     *
     * Both are ledger movements out of the cart, which is what keeps the cart's projected stock
     * honest overnight — the difference is only where the cups went, and that difference is
     * exactly what "sisa" vs "reject" means in the day's numbers.
     *
     * @param  array<int,int>  $returnedByProductId  product_id => cups back into the showcase
     * @param  array<int,int>  $rejectedByProductId  product_id => cups written off
     */
    public function closeOutCart(
        User $barista,
        Cart $cart,
        array $returnedByProductId = [],
        array $rejectedByProductId = [],
    ): void {
        $this->assertBarista($barista);

        $returned = $this->normalizeQuantities($returnedByProductId, allowEmpty: true);
        $rejected = $this->normalizeQuantities($rejectedByProductId, allowEmpty: true);

        if ($returned === [] && $rejected === []) {
            throw new RuntimeException('Tidak ada cups yang dilaporkan.');
        }

        $kitchenId = $cart->kitchen_id ?? $barista->kitchen_id;

        if (! $kitchenId) {
            throw new RuntimeException('Gerobak ini belum terhubung ke dapur pusat.');
        }

        DB::transaction(function () use ($barista, $cart, $returned, $rejected, $kitchenId): void {
            $productIds = array_unique([...array_keys($returned), ...array_keys($rejected)]);
            $locked = $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, $productIds);

            // A cart cannot give back more cups than it holds. Without this the ledger would go
            // negative and "stock" would stop meaning anything for that cart.
            foreach ($productIds as $productId) {
                $moving = ($returned[$productId] ?? 0) + ($rejected[$productId] ?? 0);
                $available = $locked[$productId] ?? 0;

                if ($moving > $available) {
                    throw new RuntimeException(
                        "Jumlah sisa + reject untuk produk #{$productId} ({$moving}) melebihi stok gerobak ({$available})."
                    );
                }
            }

            foreach ($returned as $productId => $qty) {
                $this->ledger->transfer(
                    StockLedgerService::CART,
                    $cart->id,
                    StockLedgerService::KITCHEN,
                    $kitchenId,
                    $productId,
                    MovementType::RETURN_OUT,
                    MovementType::RETURN_IN,
                    $qty,
                    $barista->id,
                    $kitchenId,
                    'cart_closeout_return',
                    $cart->id,
                );
            }

            // Rejects leave the system entirely — there is no receiving location for a cup
            // thrown away, so this is a single OUT movement, not a transfer.
            foreach ($rejected as $productId => $qty) {
                $this->ledger->post(
                    StockLedgerService::CART,
                    $cart->id,
                    $productId,
                    MovementType::WASTE_OUT,
                    $qty,
                    $barista->id,
                    $kitchenId,
                    'cart_closeout_reject',
                    $cart->id,
                );
            }

            $this->audit($barista, 'central_stock.close_out', $cart->id, [
                'cart_id' => $cart->id,
                'returned' => $returned,
                'rejected' => $rejected,
            ]);
        });
    }

    /** Cups currently in the showcase, per product. @return array<int,int> */
    public function showcaseStock(CentralKitchen $kitchen): array
    {
        return $this->ledger->stockMap(StockLedgerService::KITCHEN, $kitchen->id);
    }

    /**
     * Today's assignment for this cart, created if the barista is handing it stock for the
     * first time today.
     *
     * R11 (one cart per staff per operating day) still holds: if this staff member is already
     * on a different cart today, that is a real conflict and the caller has to resolve it rather
     * than have the system silently move them.
     */
    private function ensureAssignment(
        User $barista,
        Cart $cart,
        User $staff,
        Carbon $date,
        int $kitchenId,
        ?int $locationId = null,
    ): StaffAssignment {
        $dateString = $date->toDateString();

        $existingForCart = StaffAssignment::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $dateString)
            ->first();

        if ($existingForCart) {
            if ((int) $existingForCart->user_id !== (int) $staff->id) {
                throw new RuntimeException(
                    "Gerobak {$cart->code} hari ini sudah ditugaskan ke staff lain."
                );
            }

            return $existingForCart;
        }

        $staffElsewhere = StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', $dateString)
            ->where('cart_id', '!=', $cart->id)
            ->exists();

        if ($staffElsewhere) {
            throw new RuntimeException(
                "{$staff->name} hari ini sudah ditugaskan di gerobak lain (R11)."
            );
        }

        // `staff_assignments.location_id` is NOT NULL, so a cart has to have a known spot before
        // it can go on the roster. Inherited from where this cart last stood rather than asked
        // for again — the barista is handing over cups, not deciding pitch locations — and only
        // demanded explicitly when the cart has no history to inherit from.
        $resolvedLocationId = $locationId ?? $this->resolveLocationId($cart);

        if (! $resolvedLocationId) {
            throw new RuntimeException(
                "Gerobak {$cart->code} belum pernah punya lokasi. Tetapkan lokasinya dulu di menu Penugasan."
            );
        }

        return StaffAssignment::query()->create([
            'user_id' => $staff->id,
            'cart_id' => $cart->id,
            'location_id' => $resolvedLocationId,
            'operating_date' => $dateString,
            'assigned_by' => $barista->id,
            'kitchen_id' => $kitchenId,
        ]);
    }

    /**
     * A cart's location for today, reusing yesterday's if the cart has no fixed one.
     *
     * Nullable on purpose: `staff_assignments.location_id` is nullable, and a cart whose spot
     * isn't decided yet should still be sellable — the location can be corrected in the panel
     * without blocking the morning.
     */
    private function resolveLocationId(Cart $cart): ?int
    {
        return StaffAssignment::query()
            ->where('cart_id', $cart->id)
            ->whereNotNull('location_id')
            ->latest('operating_date')
            ->value('location_id');
    }

    /**
     * @param  array<int|string,int|string>  $qtyByProductId
     * @return array<int,int>
     */
    private function normalizeQuantities(array $qtyByProductId, bool $allowEmpty = false): array
    {
        $rows = [];

        foreach ($qtyByProductId as $productId => $qty) {
            $productId = (int) $productId;
            $qty = (int) $qty;

            // Zero is how a form says "none of this product" — dropped rather than rejected, so
            // the client can send every product every time without special-casing.
            if ($qty === 0) {
                continue;
            }

            // R7: cups are not divisible and never negative.
            if ($qty < 0) {
                throw new RuntimeException('Jumlah cups tidak boleh negatif.');
            }

            $rows[$productId] = $qty;
        }

        if ($rows === [] && ! $allowEmpty) {
            throw new RuntimeException('Tidak ada cups yang diinput.');
        }

        return $rows;
    }

    private function assertBarista(User $user): void
    {
        if ($user->role !== Role::BARISTA) {
            throw new RuntimeException('Hanya barista yang dapat mengelola stok showcase.');
        }
    }

    /** @param array<string,mixed> $after */
    private function audit(User $actor, string $action, ?int $subjectId, array $after): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role->value,
            'action' => $action,
            'subject_type' => self::class,
            'subject_id' => $subjectId,
            'before_json' => null,
            'after_json' => $after,
            'ip' => request()?->ip(),
            'device_id' => null,
        ]);
    }
}
