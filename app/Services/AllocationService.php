<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\DailyAllocation;
use App\Models\DailyAllocationLine;
use App\Models\DailyTarget;
use App\Models\Location;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flow A — daily opening allocation (docs/02 §5, requirement 1).
 *
 * Owns the two invariants that make requirement 1 safe to leave barista-owned:
 *   - the total issued is always computed from `lines`, never accepted from the client (§4)
 *   - an allocation more than `soul.allocation_over_target_tolerance` percent above the
 *     standardised daily_targets total is escalated to PENDING_FINANCE and stock is withheld,
 *     closing the loophole where the requirement-4 approval gate is bypassed by simply
 *     over-allocating in the morning
 */
class AllocationService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Staff on shift for one operating day, each with cart/location and whether an
     * allocation already exists (docs/04 `GET /allocations/today`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function staffOnShift(string $date): array
    {
        $assignments = StaffAssignment::query()
            ->with(['user', 'cart', 'location'])
            ->whereDate('operating_date', $date)
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $cartIds = $assignments->pluck('cart_id')->unique()->values();

        $allocatedCartIds = DailyAllocation::query()
            ->whereDate('operating_date', $date)
            ->whereIn('cart_id', $cartIds)
            ->pluck('cart_id')
            ->all();

        $weekday = Carbon::parse($date)->dayOfWeek;

        $targetsByCart = DailyTarget::query()
            ->whereIn('cart_id', $cartIds)
            ->where(function ($query) use ($weekday): void {
                $query->whereNull('weekday')->orWhere('weekday', $weekday);
            })
            ->get()
            ->groupBy('cart_id');

        return $assignments->map(function (StaffAssignment $assignment) use ($allocatedCartIds, $targetsByCart): array {
            $targets = ($targetsByCart->get($assignment->cart_id) ?? collect())
                ->map(fn (DailyTarget $target): array => [
                    'product_id' => $target->product_id,
                    'target_qty' => $target->target_qty,
                ])
                ->values()
                ->all();

            return [
                'staff_id' => $assignment->user_id,
                'staff_name' => $assignment->user?->name,
                'cart_id' => $assignment->cart_id,
                'cart_code' => $assignment->cart?->code,
                'location_id' => $assignment->location_id,
                'location_name' => $assignment->location?->name,
                'has_allocation' => in_array($assignment->cart_id, $allocatedCartIds, true),
                'targets' => $targets,
            ];
        })->values()->all();
    }

    /**
     * Create (or correct) today's allocation for one cart.
     *
     * @param  array{operating_date:string, cart_id:int, staff_id:int, location_id:int, lines:array<int,array{product_id:int,qty_issued:int}>, correction_reason?:?string}  $input
     */
    public function create(array $input, User $actor): DailyAllocation
    {
        $cart = Cart::query()->findOrFail($input['cart_id']);
        $location = Location::query()->findOrFail($input['location_id']);
        $kitchenId = $cart->kitchen_id;
        $operatingDate = $input['operating_date'];
        $correctionReason = $input['correction_reason'] ?? null;

        // E20: one allocation per cart per operating day. A second requires a correction
        // reason and is recorded — never silently overwritten or blocked outright.
        $alreadyAllocatedToday = DailyAllocation::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $operatingDate)
            ->exists();

        if ($alreadyAllocatedToday && blank($correctionReason)) {
            abort(409, 'Sudah ada alokasi untuk gerobak ini hari ini. Isi alasan koreksi untuk mencatat alokasi baru.');
        }

        $isCorrection = $alreadyAllocatedToday;

        $weekday = Carbon::parse($operatingDate)->dayOfWeek;
        $targets = DailyTarget::query()
            ->where('cart_id', $cart->id)
            ->where(function ($query) use ($weekday): void {
                $query->whereNull('weekday')->orWhere('weekday', $weekday);
            })
            ->get()
            ->keyBy('product_id');

        $productIds = collect($input['lines'])->pluck('product_id')->all();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $totalIssued = 0;
        $totalTarget = 0;
        $lineRows = [];

        foreach ($input['lines'] as $line) {
            $productId = (int) $line['product_id'];
            $qtyIssued = (int) $line['qty_issued'];
            $targetQty = (int) ($targets->get($productId)?->target_qty ?? 0);

            $totalIssued += $qtyIssued;
            $totalTarget += $targetQty;

            $lineRows[] = [
                'product_id' => $productId,
                'target_qty' => $targetQty,
                'qty_issued' => $qtyIssued,
            ];
        }

        // No daily_targets configured for this cart at all: nothing to compare against, so
        // the over-target escalation cannot fire. This is an operational gap (targets should
        // exist for every active cart), not a loophole to guard against here.
        $overTargetPct = $totalTarget > 0
            ? (int) round((($totalIssued - $totalTarget) / $totalTarget) * 100)
            : 0;

        $tolerance = (int) config('soul.allocation_over_target_tolerance', 20);
        $status = $overTargetPct > $tolerance ? AllocationStatus::PENDING_FINANCE : AllocationStatus::ISSUED;

        return DB::transaction(function () use (
            $cart, $location, $kitchenId, $operatingDate, $input, $actor, $isCorrection,
            $correctionReason, $lineRows, $overTargetPct, $status, $products,
        ): DailyAllocation {
            $allocation = DailyAllocation::query()->create([
                'operating_date' => $operatingDate,
                'cart_id' => $cart->id,
                'staff_id' => $input['staff_id'],
                'kitchen_id' => $kitchenId,
                'barista_id' => $actor->id,
                'location_id' => $location->id,
                'status' => $status,
                'is_correction' => $isCorrection,
                'correction_reason' => $isCorrection ? $correctionReason : null,
                'over_target_pct' => max($overTargetPct, 0),
                'issued_at' => $status === AllocationStatus::ISSUED ? now() : null,
            ]);

            foreach ($lineRows as $row) {
                DailyAllocationLine::query()->create([
                    'allocation_id' => $allocation->id,
                    'product_id' => $row['product_id'],
                    'target_qty' => $row['target_qty'],
                    'qty_issued' => $row['qty_issued'],
                ]);
            }

            if ($status === AllocationStatus::ISSUED) {
                $productIds = array_column($lineRows, 'product_id');
                $locked = $this->ledger->lockAndProject(StockLedgerService::KITCHEN, $kitchenId, $productIds);

                $shortages = [];
                foreach ($lineRows as $row) {
                    $available = $locked[$row['product_id']] ?? 0;
                    if ($available < $row['qty_issued']) {
                        $name = $products->get($row['product_id'])?->name ?? "#{$row['product_id']}";
                        $shortages[] = "{$name} (butuh {$row['qty_issued']}, tersedia {$available})";
                    }
                }

                if ($shortages !== []) {
                    abort(422, 'Stok kitchen tidak cukup untuk: '.implode(', ', $shortages));
                }

                foreach ($lineRows as $row) {
                    $this->ledger->transfer(
                        StockLedgerService::KITCHEN,
                        $kitchenId,
                        StockLedgerService::CART,
                        $cart->id,
                        $row['product_id'],
                        MovementType::ALLOCATION_OUT,
                        MovementType::ALLOCATION_IN,
                        $row['qty_issued'],
                        $actor->id,
                        $kitchenId,
                        'daily_allocation',
                        $allocation->id,
                    );
                }
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'actor_role' => $actor->role->value,
                'action' => $isCorrection ? 'daily_allocation.correction' : 'daily_allocation.create',
                'subject_type' => DailyAllocation::class,
                'subject_id' => $allocation->id,
                'before_json' => null,
                'after_json' => $allocation->fresh('lines')->toArray(),
                'ip' => request()->ip(),
                'device_id' => null,
            ]);

            if ($status === AllocationStatus::ISSUED) {
                $this->events->publish(
                    type: 'DailyAllocationIssued',
                    title: 'Alokasi harian siap',
                    body: sprintf(
                        '%d cups, lokasi: %s',
                        array_sum(array_column($lineRows, 'qty_issued')),
                        $location->name,
                    ),
                    channels: ["user.{$allocation->staff_id}", "cart.{$allocation->cart_id}", "kitchen.{$kitchenId}"],
                    notifyUserIds: [$allocation->staff_id],
                );
            } else {
                $escalationRecipients = User::query()
                    ->whereIn('role', [Role::FINANCE, Role::ADMINISTRATOR])
                    ->pluck('id')
                    ->all();

                $this->events->publish(
                    type: 'AllocationOverTarget',
                    title: 'Alokasi melebihi target',
                    body: sprintf(
                        'Gerobak %s alokasi %d%% di atas target — menunggu approval Finance.',
                        $cart->code,
                        $overTargetPct,
                    ),
                    channels: ['role.FINANCE', 'role.ADMINISTRATOR', "kitchen.{$kitchenId}"],
                    notifyUserIds: $escalationRecipients,
                );
            }

            return $allocation->load('lines.product', 'cart', 'staff', 'location');
        });
    }

    /**
     * Projected stock for every product with a movement at one location, ordered by the
     * paper-form product order (§3.1).
     *
     * @return array<int, array{product_id:int, product_name:string, qty:int}>
     */
    public function stockRows(string $locationType, int $locationId): array
    {
        $map = $this->ledger->stockMap($locationType, $locationId);

        if ($map === []) {
            return [];
        }

        $names = Product::query()
            ->whereIn('id', array_keys($map))
            ->orderBy('sort_order')
            ->pluck('name', 'id');

        $rows = [];
        foreach ($names as $productId => $name) {
            $rows[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'qty' => $map[$productId],
            ];
        }

        return $rows;
    }
}
