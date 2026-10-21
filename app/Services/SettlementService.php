<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\StaffAssignment;
use App\Models\StaffLocationPing;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Setoran — the end of a cart's day, at the Finance desk.
 *
 * WHAT FINANCE TYPES, AND WHAT THEY MUST NOT HAVE TO
 * ---------------------------------------------------
 * They type the money: how much came back as cash, as QRIS, as transfer. That is the one thing
 * only the person receiving it knows.
 *
 * Everything else is already in the system and is computed here rather than asked for: how many
 * cups the cart was issued, how many it sold, how many are still on it, and what those sales came
 * to. Asking a queue of staff to recite numbers the database already holds is how a reconciliation
 * turns into an argument about arithmetic.
 *
 * TWO STEPS, BECAUSE THE DAY HAS TWO STEPS
 * ----------------------------------------
 * `record()` takes the money while the staff member is standing there — fast, because there is a
 * queue behind them. `approve()` deals with the cups afterwards: what goes back to the showcase to
 * sell tomorrow, and what is thrown away today. Those are different decisions made at different
 * moments, and a single form for both would keep the queue waiting on the second one.
 *
 * THE OVERLAP WITH THE BARISTA'S CLOSE-OUT, HANDLED HONESTLY
 * ----------------------------------------------------------
 * "Tutup Coffee Bike" already moves leftover cups back to the kitchen or writes them off. If a barista
 * has done that before Finance approves, the cart's stock is already zero and this flow will
 * offer nothing to disposition — the quantities here are always measured against LIVE cart stock
 * under a lock, never against what was remaining when the deposit was recorded. So whoever gets
 * there first records the movement and the other sees nothing left, instead of both posting it and
 * driving the ledger negative.
 */
class SettlementService
{
    public function requiresClosing(Carbon $date): bool
    {
        return $date->toDateString() >= (string) config('soul.closing_started_on', '2026-10-18');
    }

    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly EventPublisher $events,
        private readonly JournalService $journals,
    ) {}

    /**
     * The queue: every cart that traded today, and where its deposit has got to.
     *
     * Ordered by "not yet deposited" first, because that is the working list — a Finance user
     * opens this screen to find out who they are still waiting for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queue(?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->startOfDay();
        $viewerRole = Auth::user()?->role;
        $showClosingLocation = in_array($viewerRole, [Role::ADMINISTRATOR, Role::FINANCE], true);

        $assignmentRows = StaffAssignment::query()
            ->with(['cart:id,code,kitchen_id', 'cart.kitchen:id,lat,lng,geofence_m', 'user:id,name', 'location:id,name', 'kitchen:id,lat,lng,geofence_m'])
            ->whereDate('operating_date', $date->toDateString())
            ->orderBy('id')->get();

        $sales = DB::table('sales')
            ->selectRaw("cart_id, COUNT(*) as trx, COALESCE(SUM(total_qty),0) as cups, COALESCE(SUM(total_amount_minor),0) as revenue, COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount_minor ELSE 0 END),0) as cash, COALESCE(SUM(CASE WHEN payment_method = 'qris' THEN total_amount_minor ELSE 0 END),0) as qris, COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount_minor ELSE 0 END),0) as transfer")
            ->whereDate('operating_date', $date->toDateString())
            // A voided sale is money never actually taken — Finance must not be told to expect it.
            ->whereNull('voided_at')
            ->groupBy('cart_id')
            ->get()
            ->keyBy('cart_id');

        $settlements = Settlement::query()
            ->whereDate('operating_date', $date->toDateString())
            ->get()
            ->keyBy('cart_id');
        $closings = RiderClosing::query()->with('lines')
            ->whereDate('operating_date', $date->toDateString())->get()->keyBy('cart_id');
        $closeoutVarianceCounts = DB::table('cart_closeout_variances')
            ->where('operating_date', $date->toDateString())
            ->selectRaw('cart_id, COUNT(*) AS flagged')->groupBy('cart_id')
            ->pluck('flagged', 'cart_id');
        $saleStaffIds = Sale::query()
            ->whereDate('operating_date', $date->toDateString())
            ->whereNull('voided_at')
            ->get(['cart_id', 'staff_id'])
            ->groupBy('cart_id')
            ->map(fn ($rows): array => $rows->pluck('staff_id')->unique()->values()->all());
        $saleStaffNames = User::query()
            ->whereIn('id', $saleStaffIds->flatten()->unique()->all())
            ->pluck('name', 'id');

        // Keep every cart that received Add Stock, including one later reassigned.
        // A legacy manual row is relevant only if sales/settlement already exist for
        // that cart; it must not manufacture a Finance queue item on its own.
        $assignments = $assignmentRows
            ->filter(fn (StaffAssignment $row): bool => $row->source === 'hand_to_cart'
                || $sales->has($row->cart_id) || $settlements->has($row->cart_id) || $closings->has($row->cart_id))
            ->sortBy(fn (StaffAssignment $row): array => [$row->source === 'hand_to_cart' ? 1 : 0, $row->id])
            ->keyBy('cart_id')
            ->values();

        // The newest server-recorded ping is authoritative; an earlier kitchen ping cannot make
        // a Rider who has since departed appear to have arrived. One indexed day lookup for all.
        $lastPingIds = DB::table('staff_location_pings')
            ->where('operating_date', $date->toDateString())
            ->whereIn('user_id', $assignments->pluck('user_id')->all())
            ->groupBy('user_id')
            ->selectRaw('MAX(id)');
        $lastPings = StaffLocationPing::query()->whereIn('id', $lastPingIds)
            ->get()->keyBy('user_id');

        // Every cart's remaining cups in ONE query rather than one per cart. This list is the
        // Finance screen's main view refreshes after queue events, so a per-row projection
        // would add a query per cart on each update.
        $remaining = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->whereIn('location_id', $assignments->pluck('cart_id')->filter()->unique()->all())
            ->groupBy('location_id')
            ->selectRaw('location_id, SUM(qty_delta) as qty')
            ->pluck('qty', 'location_id')
            ->map(fn ($qty): int => max(0, (int) $qty))
            ->all();

        return $assignments
            ->map(function (StaffAssignment $assignment) use ($date, $sales, $settlements, $closings, $closeoutVarianceCounts, $saleStaffIds, $saleStaffNames, $remaining, $lastPings, $showClosingLocation): array {
                $sale = $sales->get($assignment->cart_id);
                $settlement = $settlements->get($assignment->cart_id);
                $closing = $closings->get($assignment->cart_id);
                $ping = $lastPings->get($assignment->user_id);
                $kitchen = $assignment->kitchen ?? $assignment->cart?->kitchen;
                $arrivedAt = $this->isInsideKitchen($ping, $kitchen) ? $ping->recorded_at->toIso8601String() : null;

                return [
                    'cart_id' => $assignment->cart_id,
                    'cart_code' => $assignment->cart?->code,
                    'staff_id' => $assignment->user_id,
                    'staff_name' => $assignment->user?->name,
                    'sale_staff_ids' => $saleStaffIds->get($assignment->cart_id, []),
                    'sale_staff_names' => collect($saleStaffIds->get($assignment->cart_id, []))
                        ->map(fn ($id): string => $saleStaffNames->get($id) ?? 'Rider #'.$id)->all(),
                    'area' => $assignment->location?->name,
                    'transactions' => (int) ($sale->trx ?? 0),
                    'closing_status' => $closing ? 'Sudah closing' : ($this->requiresClosing($date) ? 'Menunggu closing Rider' : 'Legacy'),
                    'closing_id' => $closing?->id,
                    'outside_closing_point' => $showClosingLocation && (bool) ($closing?->outside_closing_point ?? false),
                    'closing_distance_m' => $showClosingLocation ? $closing?->closing_distance_m : null,
                    'closing_required' => $this->requiresClosing($date),
                    'carry_warning' => (bool) $closing?->lines->contains(fn ($line): bool => $line->qty_carry > 0),
                    'closeout_variance_count' => (int) ($closeoutVarianceCounts[$assignment->cart_id] ?? 0),
                    'cups_sold' => (int) ($closing?->lines->sum('qty_sold') ?? $sale->cups ?? 0),
                    'expected_total' => (int) ($closing?->total_minor ?? $sale->revenue ?? 0),
                    'expected_total_minor' => (int) ($closing?->total_minor ?? $sale->revenue ?? 0),
                    'expected_cash_minor' => (int) ($closing?->cash_minor ?? $sale->cash ?? 0),
                    'expected_qris_minor' => (int) ($closing?->qris_minor ?? $sale->qris ?? 0),
                    'expected_transfer_minor' => (int) ($closing?->transfer_minor ?? $sale->transfer ?? 0),
                    'arrived_at' => $arrivedAt,
                    'cash_minor' => $settlement?->cash_minor,
                    'qris_minor' => $settlement?->qris_minor,
                    'transfer_minor' => $settlement?->transfer_minor,
                    'variance_minor' => $settlement?->variance_minor,
                    'method_variance_flagged' => (bool) ($settlement?->method_variance_flagged ?? false),
                    'cash_variance_minor' => $settlement?->cash_variance_minor,
                    'qris_variance_minor' => $settlement?->qris_variance_minor,
                    'transfer_variance_minor' => $settlement?->transfer_variance_minor,
                    'self_approved' => (bool) ($settlement?->self_approved ?? false),
                    'cups_remaining' => (int) ($closing?->lines->sum('qty_remaining') ?? $remaining[$assignment->cart_id] ?? 0),
                    'cups_live_remaining' => $remaining[$assignment->cart_id] ?? 0,
                    'settlement_id' => $settlement?->id,
                    'settlement_status' => $settlement?->status,
                    'snapshot_expected_total_minor' => $settlement?->expected_total_minor,
                    'expected_stale' => (bool) ($settlement?->expected_stale ?? false),
                    'stale_delta_minor' => (int) ($settlement?->stale_delta_minor ?? 0),
                ];
            })
            ->sortBy(fn (array $row): array => [$row['settlement_id'] !== null ? 2 : ($row['arrived_at'] !== null ? 0 : 1), $row['cart_code'] ?? ''])
            ->values()
            ->all();
    }

    /**
     * Everything the deposit form should already know for one cart.
     *
     * @return array<string, mixed>
     */
    public function draft(Cart $cart, ?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->startOfDay();
        $closing = RiderClosing::query()->with('lines')->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())->first();
        if (! $closing && $this->requiresClosing($date)) {
            throw new RuntimeException('Menunggu closing Rider dulu ya, setoran belum bisa dibuka.');
        }

        $assignment = StaffAssignment::query()
            ->with(['user:id,name', 'location:id,name'])
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())
            ->where('source', 'hand_to_cart')
            ->latest('id')
            ->first();
        if (! $assignment && (Sale::query()->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())->exists()
            || Settlement::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->exists())) {
            $assignment = StaffAssignment::query()
                ->with(['user:id,name', 'location:id,name'])
                ->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())
                ->latest('id')
                ->first();
        }
        $saleStaffIds = Sale::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())
            ->whereNull('voided_at')
            ->distinct()
            ->pluck('staff_id')
            ->all();

        $closingLines = $closing?->lines->keyBy('product_id') ?? collect();
        $legacyExpectations = null;
        $legacySold = [];
        if (! $closing) {
            $legacyExpectations = Sale::query()
                ->where('cart_id', $cart->id)->whereDate('operating_date', $date->toDateString())
                ->whereNull('voided_at')
                ->selectRaw("COALESCE(SUM(total_amount_minor),0) as total, COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount_minor ELSE 0 END),0) as cash, COALESCE(SUM(CASE WHEN payment_method = 'qris' THEN total_amount_minor ELSE 0 END),0) as qris, COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount_minor ELSE 0 END),0) as transfer")
                ->first();
            $legacySold = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->where('sales.cart_id', $cart->id)->whereDate('sales.operating_date', $date->toDateString())
                ->whereNull('sales.voided_at')->groupBy('sale_lines.product_id')
                ->selectRaw('sale_lines.product_id, SUM(sale_lines.qty) AS qty')
                ->pluck('qty', 'product_id')->map(fn ($qty): int => (int) $qty)->all();
        }

        // Everything that ARRIVED on the cart today: the morning hand-over plus any refills.
        // Positive movements only — a sale is negative and would otherwise cancel out the issue.
        // SALE_VOID_IN and OPNAME_ADJUSTMENT are excluded even though both are positive: neither
        // is a fresh hand-over, and counting a voided sale's stock coming back — or a stock-take
        // correction — as "issued today" would inflate the figure Finance reads as "what arrived".
        $issued = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cart->id)
            ->whereDate('created_at', $date->toDateString())
            ->where('qty_delta', '>', 0)
            ->whereNotIn('movement_type', [MovementType::SALE_VOID_IN->value, MovementType::OPNAME_ADJUSTMENT->value])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_delta) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();

        $remaining = $this->ledger->stockMap(StockLedgerService::CART, $cart->id);

        $productIds = array_unique([...array_keys($issued), ...$closingLines->keys()->all(), ...array_keys($legacySold), ...array_keys($remaining)]);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'unit']);

        return [
            'cart_id' => $cart->id,
            'cart_code' => $cart->code,
            'operating_date' => $date->toDateString(),
            'staff_id' => $assignment?->user_id,
            'staff_name' => $assignment?->user?->name,
            'sale_staff_ids' => $saleStaffIds,
            'area' => $assignment?->location?->name,
            'closing_id' => $closing?->id,
            'closing_status' => $closing ? 'Sudah closing' : 'Legacy',
            'carry_warning' => $closing?->lines->contains(fn ($line): bool => $line->qty_carry > 0) ?? false,
            'expected_total' => $closing?->total_minor ?? (int) $legacyExpectations?->total,
            'expected_total_minor' => $closing?->total_minor ?? (int) $legacyExpectations?->total,
            'expected_cash_minor' => $closing?->cash_minor ?? (int) $legacyExpectations?->cash,
            'expected_qris_minor' => $closing?->qris_minor ?? (int) $legacyExpectations?->qris,
            'expected_transfer_minor' => $closing?->transfer_minor ?? (int) $legacyExpectations?->transfer,
            'lines' => $products->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'qty_issued' => $closing ? ($closingLines->get($product->id)?->qty_in ?? 0) + ($closingLines->get($product->id)?->qty_carry ?? 0) : ($issued[$product->id] ?? 0),
                'qty_carry' => $closingLines->get($product->id)?->qty_carry ?? 0,
                'qty_sold' => $closingLines->get($product->id)?->qty_sold ?? $legacySold[$product->id] ?? 0,
                'qty_closing_remaining' => $closingLines->get($product->id)?->qty_remaining ?? $remaining[$product->id] ?? 0,
                'qty_wasted' => $closingLines->get($product->id)?->qty_wasted ?? 0,
                // Live, not a snapshot: this is what is physically on the cart right now, which
                // is the only number the disposition at approval can be measured against.
                'qty_remaining' => max(0, $remaining[$product->id] ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * Records the money handed over. One settlement per cart per day.
     *
     * @param  array{cash: int, qris: int, transfer: int}  $money
     */
    public function record(
        Cart $cart,
        User $finance,
        array $money,
        ?string $varianceReason = null,
        ?Carbon $date = null,
    ): Settlement {
        if (! $finance->role->grants(Role::FINANCE)) {
            throw new RuntimeException('Hanya Finance atau Administrator yang menerima setoran.');
        }

        $date = ($date ?? Carbon::today())->startOfDay();

        return DB::transaction(function () use ($cart, $finance, $money, $varianceReason, $date): Settlement {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($this->requiresClosing($date) && ! RiderClosing::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->exists()) {
                throw new RuntimeException('Menunggu closing Rider dulu ya, setoran belum bisa dicatat.');
            }
            $existing = Settlement::query()
                ->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            $draft = $this->draft($cart, $date);

            $cash = max(0, (int) ($money['cash'] ?? 0));
            $qris = max(0, (int) ($money['qris'] ?? 0));
            $transfer = max(0, (int) ($money['transfer'] ?? 0));
            $declared = $cash + $qris + $transfer;
            if ($declared > 1_000_000_000_000) {
                throw new RuntimeException('Total setoran melampaui batas Rp 1.000.000.000.000.');
            }
            $expected = (int) $draft['expected_total'];
            $variance = $declared - $expected;
            $methodVariances = [
                'cash_variance_minor' => $cash - (int) $draft['expected_cash_minor'],
                'qris_variance_minor' => $qris - (int) $draft['expected_qris_minor'],
                'transfer_variance_minor' => $transfer - (int) $draft['expected_transfer_minor'],
            ];
            $methodFlagged = collect($methodVariances)->contains(fn (int $value): bool => $value !== 0);

            $closingCogs = RiderClosing::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->value('cogs_minor');
            $cogs = $closingCogs !== null ? (int) $closingCogs : (int) DB::table('sale_lines')
                ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->where('sales.cart_id', $cart->id)->whereDate('sales.operating_date', $date->toDateString())
                ->whereNull('sales.voided_at')
                ->selectRaw('COALESCE(SUM(sale_lines.qty * sale_lines.unit_cost_minor),0) AS total')->value('total');
            $values = [
                'operating_date' => $date->toDateString(),
                'cart_id' => $cart->id,
                // The latest handover names the cart custodian for settlement.
                // Individual sale staff IDs stay on sales and in the draft.
                'staff_id' => $draft['staff_id'] ?? $finance->id,
                'status' => $variance !== 0 ? 'VARIANCE_FLAGGED' : 'SUBMITTED',
                'cash_minor' => $cash,
                'qris_minor' => $qris,
                'transfer_minor' => $transfer,
                'declared_total_minor' => $declared,
                'expected_total_minor' => $expected,
                'expected_cash_minor' => $draft['expected_cash_minor'],
                'expected_qris_minor' => $draft['expected_qris_minor'],
                'expected_transfer_minor' => $draft['expected_transfer_minor'],
                'expected_cogs_minor' => $cogs,
                'expected_stale' => false,
                'stale_delta_minor' => 0,
                'variance_minor' => $variance,
                'variance_reason' => $varianceReason,
                'recorded_by' => $finance->id,
                'method_variance_flagged' => $methodFlagged,
                ...$methodVariances,
            ];

            if ($existing) {
                if ($existing->reconciled_at !== null || ! in_array($existing->status, ['SUBMITTED', 'VARIANCE_FLAGGED'], true)) {
                    throw new RuntimeException('Setoran yang sudah disetujui tidak dapat dicatat ulang.');
                }
                AuditLog::query()->create([
                    'actor_id' => $finance->id, 'actor_role' => $finance->role->value,
                    'action' => 'settlement.rerecorded', 'subject_type' => Settlement::class,
                    'subject_id' => $existing->id,
                    'before_json' => $existing->only(array_keys($values)), 'after_json' => $values,
                    'ip' => request()->ip(), 'device_id' => null,
                ]);
                $existing->update($values);
                $existing->lines()->delete();
                $settlement = $existing;
            } else {
                $settlement = Settlement::query()->create($values);
            }

            foreach ($draft['lines'] as $line) {
                SettlementLine::query()->create([
                    'settlement_id' => $settlement->id,
                    'product_id' => $line['product_id'],
                    'qty_issued' => $line['qty_issued'],
                    'qty_sold' => $line['qty_sold'],
                    'qty_remaining' => $line['qty_remaining'],
                    'qty_wasted' => $line['qty_wasted'],
                    'variance_qty' => $line['qty_issued'] - ($line['qty_sold'] + $line['qty_wasted']
                        + ($draft['closing_id'] ? $line['qty_closing_remaining'] : $line['qty_remaining'])),
                ]);
            }

            if ($variance !== 0) {
                AuditLog::query()->create([
                    'actor_id' => $finance->id,
                    'actor_role' => $finance->role->value,
                    'action' => 'settlement.variance_flagged',
                    'subject_type' => Settlement::class,
                    'subject_id' => $settlement->id,
                    'before_json' => null,
                    'after_json' => [
                        'cart_id' => $cart->id,
                        'cart_code' => $cart->code,
                        'operating_date' => $date->toDateString(),
                        'expected_total_minor' => $expected,
                        'declared_total_minor' => $declared,
                        'variance_minor' => $variance,
                        'variance_reason' => $varianceReason,
                    ],
                    'ip' => request()->ip(),
                    'device_id' => null,
                ]);

                $recipientIds = User::query()
                    ->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT])
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->pluck('id')->all();

                $this->events->publish(
                    type: 'SettlementVarianceFlagged',
                    title: 'Selisih setoran perlu ditinjau',
                    body: sprintf(
                        'Coffee Bike %s · %s · Ekspektasi Rp %s · Disetor Rp %s · Selisih Rp %s',
                        $cart->code,
                        $date->toDateString(),
                        number_format($expected, 0, ',', '.'),
                        number_format($declared, 0, ',', '.'),
                        number_format($variance, 0, ',', '.'),
                    ),
                    channels: array_map(fn (int $id): string => "user.{$id}", $recipientIds),
                    notifyUserIds: $recipientIds,
                    status: 'VARIANCE_FLAGGED',
                    includeActorPush: true,
                );
            }

            if ($methodFlagged) {
                AuditLog::recordAction($finance, 'settlement.method_variance_flagged', $settlement, null, [
                    'cart_id' => $cart->id, 'operating_date' => $date->toDateString(),
                    ...$methodVariances,
                ]);
                $recipientIds = User::query()->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT])
                    ->where('is_active', true)->orderBy('id')->pluck('id')->all();
                $this->events->publish(
                    type: 'SettlementMethodVarianceFlagged',
                    title: 'Selisih metode setoran perlu dicek',
                    body: sprintf('Setoran Coffee Bike %s: tunai %s, QRIS %s, transfer %s. Cek rincian metode ya.',
                        $cart->code, number_format($methodVariances['cash_variance_minor'], 0, ',', '.'),
                        number_format($methodVariances['qris_variance_minor'], 0, ',', '.'),
                        number_format($methodVariances['transfer_variance_minor'], 0, ',', '.')),
                    channels: array_map(fn (int $id): string => "user.{$id}", $recipientIds),
                    notifyUserIds: $recipientIds, status: $settlement->status, includeActorPush: true,
                    context: ['cart_id' => $cart->id, 'operating_date' => $date->toDateString(), 'source_id' => $settlement->id],
                );
            }
            $riderId = $this->settlementRiderId($settlement);
            $this->events->publish(
                type: 'SettlementRecorded',
                title: 'Setoran tercatat',
                body: sprintf('Setoran Coffee Bike %s tercatat.', $cart->code),
                channels: ['role.FINANCE', 'role.MANAGEMENT', 'user.'.$riderId],
                notifyUserIds: [$riderId],
                status: $settlement->status,
                context: [
                    'cart_id' => $settlement->cart_id,
                    'operating_date' => $settlement->operating_date->toDateString(),
                    'source_id' => $settlement->id,
                ],
            );

            return $settlement->load('lines');
        });
    }

    public function markExpectedStale(int $cartId, Carbon $date, User $actor, int $saleDeltaMinor, bool $stockCorrectionNeeded = false): void
    {
        $settlement = Settlement::query()->where('cart_id', $cartId)
            ->whereDate('operating_date', $date->toDateString())->lockForUpdate()->first();
        if (! $settlement) {
            return;
        }

        $before = ['expected_stale' => $settlement->expected_stale, 'stale_delta_minor' => $settlement->stale_delta_minor];
        // The signed change is exact within the sale/void transaction and remains correct under
        // MySQL REPEATABLE READ, where a SUM could miss a concurrent committed sale.
        $settlement->update(['expected_stale' => true, 'stale_delta_minor' => $settlement->stale_delta_minor + $saleDeltaMinor]);
        AuditLog::query()->create([
            'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
            'action' => 'settlement.expected_stale', 'subject_type' => Settlement::class,
            'subject_id' => $settlement->id, 'before_json' => $before,
            'after_json' => ['expected_stale' => true, 'stale_delta_minor' => $settlement->stale_delta_minor],
            'ip' => request()->ip(), 'device_id' => null,
        ]);
        $recipientIds = User::query()->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT])
            ->where('is_active', true)->pluck('id')->all();
        $this->events->publish(
            type: 'SettlementExpectedStale', title: 'Ekspektasi setoran berubah',
            body: sprintf('Ekspektasi Coffee Bike %s berubah setelah setoran dicatat.%s',
                $settlement->cart?->code,
                $stockCorrectionNeeded ? ' Periksa stok melalui Stock Opname karena stok Coffee Bike berubah setelah setoran dicatat.' : ''),
            channels: array_merge(['role.FINANCE', 'role.MANAGEMENT'], array_map(fn (int $id): string => "user.{$id}", $recipientIds)),
            notifyUserIds: $recipientIds, status: $settlement->status,
            context: ['cart_id' => $cartId, 'operating_date' => $date->toDateString(), 'source_id' => $settlement->id],
            includeActorPush: true,
        );
    }

    /**
     * Approves the deposit and settles the cups still on the cart.
     *
     * @param  array<int, array{product_id: int, qty_returned: int, qty_rejected: int}>  $disposition
     */
    public function approve(Settlement $settlement, User $finance, array $disposition, ?string $note = null): Settlement
    {
        if (! $finance->role->grants(Role::FINANCE)) {
            throw new RuntimeException('Hanya Finance atau Administrator yang menyetujui setoran.');
        }

        return DB::transaction(function () use ($settlement, $finance, $disposition, $note): Settlement {
            Cart::query()->whereKey($settlement->cart_id)->lockForUpdate()->firstOrFail();
            $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($locked->reconciled_at !== null || ! in_array($locked->status, ['SUBMITTED', 'VARIANCE_FLAGGED'], true)) {
                throw new RuntimeException('Setoran ini sudah disetujui.');
            }
            if ($locked->expected_stale) {
                throw new RuntimeException('Closing Rider berubah. Catat ulang setoran sebelum approve ya.');
            }

            $cart = Cart::query()->findOrFail($locked->cart_id);
            $kitchenId = $cart->kitchen_id;

            $rows = [];

            foreach ($disposition as $entry) {
                $productId = (int) ($entry['product_id'] ?? 0);
                $returned = max(0, (int) ($entry['qty_returned'] ?? 0));
                $rejected = max(0, (int) ($entry['qty_rejected'] ?? 0));

                if ($productId > 0 && ($returned + $rejected) > 0) {
                    $rows[$productId] = ['returned' => $returned, 'rejected' => $rejected];
                }
            }

            $liveLines = $rows === [] ? collect() : collect($this->draft($cart, $locked->operating_date)['lines'])->keyBy('product_id');

            if ($rows !== []) {
                if (! $kitchenId) {
                    throw new RuntimeException('Coffee Bike ini belum terhubung ke dapur pusat, cups sisa tidak bisa dikembalikan.');
                }

                // Measured against LIVE stock under a lock, never against the numbers captured
                // when the deposit was recorded — see the class docblock for the barista
                // close-out that may have got here first.
                $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, array_keys($rows));
                $available = $this->currentCartStock($cart->id, array_keys($rows));

                foreach ($rows as $productId => $qty) {
                    $moving = $qty['returned'] + $qty['rejected'];
                    $onHand = (int) ($available[$productId] ?? 0);

                    if ($moving > $onHand) {
                        $name = Product::query()->whereKey($productId)->value('name') ?? ('#'.$productId);

                        throw new RuntimeException(sprintf(
                            'Sisa + reject untuk %s (%d) melebihi stok Coffee Bike yang tinggal %d. Mungkin Coffee Bike ini sudah ditutup Produksi.',
                            $name,
                            $moving,
                            $onHand,
                        ));
                    }
                }

                foreach ($rows as $productId => $qty) {
                    if ($qty['returned'] > 0) {
                        // Back to the showcase to sell tomorrow.
                        $this->ledger->transfer(
                            StockLedgerService::CART,
                            $cart->id,
                            StockLedgerService::KITCHEN,
                            $kitchenId,
                            $productId,
                            MovementType::RETURN_OUT,
                            MovementType::RETURN_IN,
                            $qty['returned'],
                            $finance->id,
                            $kitchenId,
                            'settlement_return',
                            $locked->id,
                        );
                    }

                    if ($qty['rejected'] > 0) {
                        // Thrown away now. A single OUT movement, because there is no receiving
                        // location for a cup that is being discarded.
                        $this->ledger->post(
                            locationType: StockLedgerService::CART,
                            locationId: $cart->id,
                            productId: $productId,
                            movementType: MovementType::WASTE_OUT,
                            qty: $qty['rejected'],
                            actorId: $finance->id,
                            kitchenId: $kitchenId,
                            refType: 'settlement_reject',
                            refId: $locked->id,
                        );
                    }

                    $line = SettlementLine::query()->firstOrNew(['settlement_id' => $locked->id, 'product_id' => $productId]);
                    if (! $line->exists) {
                        $liveLine = $liveLines->get($productId);
                        $line->qty_issued = (int) ($liveLine['qty_issued'] ?? 0);
                        $line->qty_sold = (int) ($liveLine['qty_sold'] ?? 0);
                        $line->variance_qty = $line->qty_issued - $line->qty_sold - (int) ($liveLine['qty_remaining'] ?? 0);
                    }
                    $line->qty_wasted += $qty['rejected'];
                    $line->qty_remaining = $qty['returned'];
                    $line->save();
                }
            }

            $liveStock = $this->currentCartStock($cart->id);
            if (collect($liveStock)->contains(fn (int $qty): bool => $qty > 0)) {
                throw new RuntimeException('Masih ada cups di Coffee Bike. Tentukan jumlah kembali atau reject sebelum menyetujui setoran.');
            }

            // Disposition takes product cost locks before journal locks. Check the settlement
            // period afterward in the same transaction so a closed period rolls everything back.
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $period = AccountingPeriod::query()->where('year', $locked->operating_date->year)
                ->where('month', $locked->operating_date->month)->lockForUpdate()->first();
            if ($period?->status === 'CLOSED') {
                throw new RuntimeException('Periode akuntansi tanggal setoran sudah ditutup. Setoran belum disetujui.');
            }

            $beforeStatus = $locked->status;
            $locked->status = 'RECONCILED';
            $locked->reconciled_by = $finance->id;
            $locked->reconciled_at = now();
            $locked->self_approved = $locked->recorded_by !== null && $locked->recorded_by === $finance->id;

            if ($note !== null && trim($note) !== '') {
                $locked->variance_reason = trim(($locked->variance_reason ? $locked->variance_reason.' — ' : '').$note);
            }

            $locked->save();

            app(RiderCommissionService::class)->lockForSettlement($locked->cart_id, $locked->operating_date->toDateString());

            $this->postJournals($locked, $finance);

            AuditLog::recordAction($finance, 'settlement.approved', $locked,
                ['status' => $beforeStatus, 'recorded_by' => $locked->recorded_by],
                ['status' => 'RECONCILED', 'reconciled_by' => $finance->id,
                    'self_approved' => $locked->self_approved]);
            if ($locked->self_approved) {
                $recipientIds = User::query()->where('role', Role::MANAGEMENT)->where('is_active', true)
                    ->orderBy('id')->pluck('id')->all();
                $this->events->publish(
                    type: 'SettlementSelfApproved', title: 'Setoran disetujui pencatatnya',
                    body: sprintf('Setoran Coffee Bike %s disetujui oleh pencatatnya sendiri. Cek auditnya ya.', $cart->code),
                    channels: array_map(fn (int $id): string => "user.{$id}", $recipientIds),
                    notifyUserIds: $recipientIds, status: $locked->status, includeActorPush: true,
                    context: ['cart_id' => $cart->id, 'operating_date' => $locked->operating_date->toDateString(), 'source_id' => $locked->id],
                );
            }
            $riderId = $this->settlementRiderId($locked);
            $this->events->publish(
                type: 'SettlementApproved',
                title: 'Setoran disetujui',
                body: sprintf('Setoran Coffee Bike %s disetujui.', $cart->code),
                channels: ['role.FINANCE', 'role.MANAGEMENT', 'user.'.$riderId],
                notifyUserIds: [$riderId],
                status: $locked->status,
                context: [
                    'cart_id' => $locked->cart_id,
                    'operating_date' => $locked->operating_date->toDateString(),
                    'source_id' => $locked->id,
                ],
            );

            return $locked->fresh(['lines']);
        });
    }

    private function settlementRiderId(Settlement $settlement): int
    {
        return (int) (RiderClosing::query()->where('cart_id', $settlement->cart_id)
            ->whereDate('operating_date', $settlement->operating_date->toDateString())
            ->value('rider_id') ?? $settlement->staff_id);
    }

    private function isInsideKitchen(?StaffLocationPing $ping, ?CentralKitchen $kitchen): bool
    {
        if (! $ping || ! $kitchen || $kitchen->lat === null || $kitchen->lng === null || ! $kitchen->geofence_m) {
            return false;
        }

        $lat1 = (float) $kitchen->lat;
        $lat2 = (float) $ping->lat;
        $north = ($lat2 - $lat1) * 111_320;
        $east = ((float) $ping->lng - (float) $kitchen->lng) * 111_320 * cos(deg2rad(($lat1 + $lat2) / 2));

        return sqrt($north ** 2 + $east ** 2) <= $kitchen->geofence_m;
    }

    /** Current locking read, unaffected by an earlier MySQL REPEATABLE READ snapshot. */
    private function currentCartStock(int $cartId, ?array $productIds = null): array
    {
        $query = StockLedger::query()->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cartId);
        if ($productIds !== null) {
            $query->whereIn('product_id', $productIds);
        }

        return $query->lockForUpdate()->get(['product_id', 'qty_delta'])
            ->groupBy('product_id')->map(fn ($rows): int => (int) $rows->sum('qty_delta'))->all();
    }

    private function postJournals(Settlement $settlement, User $finance): void
    {
        $opening = JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
            ->whereDoesntHave('reversal')->first();
        if ($opening && $settlement->operating_date->lt($opening->entry_date)) {
            return;
        }
        $codes = ['1-1200', '1-1400', '4-1100', '2-1500', '6-1100', '5-1100', '1-1600'];
        $accounts = Account::query()->whereIn('code', $codes)->pluck('id', 'code');
        $line = static fn (string $code, int $debit, int $credit): array => [
            'account_id' => $accounts[$code],
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'cart_id' => $settlement->cart_id,
        ];
        $lines = [];
        if ($settlement->cash_minor > 0) {
            $lines[] = $line('1-1200', $settlement->cash_minor, 0);
        }
        $noncash = $settlement->qris_minor + $settlement->transfer_minor;
        if ($noncash > 0) {
            $lines[] = $line('1-1400', $noncash, 0);
        }
        if ($settlement->expected_total_minor > 0) {
            $split = app(TaxService::class)->splitPbjt($settlement->expected_total_minor, $settlement->operating_date->toDateString());
            if ($split['revenue'] > 0) {
                $lines[] = $line('4-1100', 0, $split['revenue']);
            }
            if ($split['tax'] > 0) {
                $lines[] = $line('2-1500', 0, $split['tax']);
            }
        }
        if ($settlement->variance_minor < 0) {
            $lines[] = $line('6-1100', -$settlement->variance_minor, 0);
        } elseif ($settlement->variance_minor > 0) {
            $lines[] = $line('6-1100', 0, $settlement->variance_minor);
        }
        $header = [
            'entry_date' => $settlement->operating_date->toDateString(),
            'source_type' => 'settlement',
            'source_id' => $settlement->id,
            'description' => 'Setoran Coffee Bike '.$settlement->cart?->code,
            'posted_by' => $finance->id,
        ];
        if ($lines !== []) {
            $this->journals->post([...$header, 'purpose' => 'SETTLEMENT'], $lines);
        }

        if ($settlement->expected_cogs_minor === null) {
            throw new RuntimeException('Setoran lama belum memiliki snapshot HPP. Catat ulang setoran sebelum menyetujui.');
        }
        // SALE_OUT already posted moving-average HPP when cups left stock.
    }
}
