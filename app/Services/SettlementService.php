<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
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
 * "Tutup Gerobak" already moves leftover cups back to the kitchen or writes them off. If a barista
 * has done that before Finance approves, the cart's stock is already zero and this flow will
 * offer nothing to disposition — the quantities here are always measured against LIVE cart stock
 * under a lock, never against what was remaining when the deposit was recorded. So whoever gets
 * there first records the movement and the other sees nothing left, instead of both posting it and
 * driving the ledger negative.
 */
class SettlementService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

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

        $assignments = StaffAssignment::query()
            ->with(['cart:id,code', 'user:id,name', 'location:id,name'])
            ->whereDate('operating_date', $date->toDateString())
            ->get();

        $sales = DB::table('sales')
            ->selectRaw('cart_id, COUNT(*) as trx, COALESCE(SUM(total_qty),0) as cups, COALESCE(SUM(total_amount_minor),0) as revenue')
            ->whereDate('operating_date', $date->toDateString())
            ->groupBy('cart_id')
            ->get()
            ->keyBy('cart_id');

        $settlements = Settlement::query()
            ->whereDate('operating_date', $date->toDateString())
            ->get()
            ->keyBy('cart_id');

        // Every cart's remaining cups in ONE query rather than one per cart. This list is the
        // Finance screen's main view and it refreshes on a timer, so a per-row projection would
        // be a query per cart per minute for the whole shift.
        $remaining = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->whereIn('location_id', $assignments->pluck('cart_id')->filter()->unique()->all())
            ->groupBy('location_id')
            ->selectRaw('location_id, SUM(qty_delta) as qty')
            ->pluck('qty', 'location_id')
            ->map(fn ($qty): int => max(0, (int) $qty))
            ->all();

        return $assignments
            ->map(function (StaffAssignment $assignment) use ($sales, $settlements, $remaining): array {
                $sale = $sales->get($assignment->cart_id);
                $settlement = $settlements->get($assignment->cart_id);

                return [
                    'cart_id' => $assignment->cart_id,
                    'cart_code' => $assignment->cart?->code,
                    'staff_id' => $assignment->user_id,
                    'staff_name' => $assignment->user?->name,
                    'area' => $assignment->location?->name,
                    'transactions' => (int) ($sale->trx ?? 0),
                    'cups_sold' => (int) ($sale->cups ?? 0),
                    'expected_total' => (int) ($sale->revenue ?? 0),
                    'cups_remaining' => $remaining[$assignment->cart_id] ?? 0,
                    'settlement_id' => $settlement?->id,
                    'settlement_status' => $settlement?->status,
                ];
            })
            ->sortBy(fn (array $row): array => [$row['settlement_id'] !== null ? 1 : 0, $row['cart_code'] ?? ''])
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

        $assignment = StaffAssignment::query()
            ->with(['user:id,name', 'location:id,name'])
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())
            ->first();

        $expected = (int) Sale::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())
            ->sum('total_amount_minor');

        $sold = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.cart_id', $cart->id)
            ->whereDate('sales.operating_date', $date->toDateString())
            ->groupBy('sale_lines.product_id')
            ->selectRaw('sale_lines.product_id as product_id, SUM(sale_lines.qty) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();

        // Everything that ARRIVED on the cart today: the morning hand-over plus any refills.
        // Positive movements only — a sale is negative and would otherwise cancel out the issue.
        $issued = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cart->id)
            ->whereDate('created_at', $date->toDateString())
            ->where('qty_delta', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_delta) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();

        $remaining = $this->ledger->stockMap(StockLedgerService::CART, $cart->id);

        $productIds = array_unique([...array_keys($issued), ...array_keys($sold), ...array_keys($remaining)]);

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
            'area' => $assignment?->location?->name,
            'expected_total' => $expected,
            'lines' => $products->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'qty_issued' => $issued[$product->id] ?? 0,
                'qty_sold' => $sold[$product->id] ?? 0,
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
        if (! in_array($finance->role, [Role::FINANCE, Role::ADMINISTRATOR], true)) {
            throw new RuntimeException('Hanya Finance atau Administrator yang menerima setoran.');
        }

        $date = ($date ?? Carbon::today())->startOfDay();

        return DB::transaction(function () use ($cart, $finance, $money, $varianceReason, $date): Settlement {
            $existing = Settlement::query()
                ->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new RuntimeException('Setoran gerobak '.$cart->code.' hari ini sudah tercatat.');
            }

            $draft = $this->draft($cart, $date);

            $cash = max(0, (int) ($money['cash'] ?? 0));
            $qris = max(0, (int) ($money['qris'] ?? 0));
            $transfer = max(0, (int) ($money['transfer'] ?? 0));
            $declared = $cash + $qris + $transfer;
            $expected = (int) $draft['expected_total'];
            $variance = $declared - $expected;

            // A gap between what the transactions say and what was handed over is the whole point
            // of a reconciliation, so it is recorded rather than refused — but it has to be
            // explained, because an unexplained gap is the one thing nobody can act on later.
            if ($variance !== 0 && ($varianceReason === null || trim($varianceReason) === '')) {
                throw new RuntimeException(sprintf(
                    'Setoran %s dari transaksi tercatat (Rp %s vs Rp %s). Isi alasan selisihnya.',
                    $variance > 0 ? 'lebih' : 'kurang',
                    number_format($declared, 0, ',', '.'),
                    number_format($expected, 0, ',', '.'),
                ));
            }

            $settlement = Settlement::query()->create([
                'operating_date' => $date->toDateString(),
                'cart_id' => $cart->id,
                'staff_id' => $draft['staff_id'] ?? $finance->id,
                'status' => 'SUBMITTED',
                'cash_minor' => $cash,
                'qris_minor' => $qris,
                'transfer_minor' => $transfer,
                'declared_total_minor' => $declared,
                'expected_total_minor' => $expected,
                'variance_minor' => $variance,
                'variance_reason' => $varianceReason,
            ]);

            foreach ($draft['lines'] as $line) {
                SettlementLine::query()->create([
                    'settlement_id' => $settlement->id,
                    'product_id' => $line['product_id'],
                    'qty_issued' => $line['qty_issued'],
                    'qty_sold' => $line['qty_sold'],
                    'qty_remaining' => $line['qty_remaining'],
                    'qty_wasted' => 0,
                    'variance_qty' => $line['qty_issued'] - ($line['qty_sold'] + $line['qty_remaining']),
                ]);
            }

            return $settlement->load('lines');
        });
    }

    /**
     * Approves the deposit and settles the cups still on the cart.
     *
     * @param  array<int, array{product_id: int, qty_returned: int, qty_rejected: int}>  $disposition
     */
    public function approve(Settlement $settlement, User $finance, array $disposition, ?string $note = null): Settlement
    {
        if (! in_array($finance->role, [Role::FINANCE, Role::ADMINISTRATOR], true)) {
            throw new RuntimeException('Hanya Finance atau Administrator yang menyetujui setoran.');
        }

        return DB::transaction(function () use ($settlement, $finance, $disposition, $note): Settlement {
            $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'RECONCILED') {
                throw new RuntimeException('Setoran ini sudah disetujui.');
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

            if ($rows !== []) {
                if (! $kitchenId) {
                    throw new RuntimeException('Gerobak ini belum terhubung ke dapur pusat, cups sisa tidak bisa dikembalikan.');
                }

                // Measured against LIVE stock under a lock, never against the numbers captured
                // when the deposit was recorded — see the class docblock for the barista
                // close-out that may have got here first.
                $available = $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, array_keys($rows));

                foreach ($rows as $productId => $qty) {
                    $moving = $qty['returned'] + $qty['rejected'];
                    $onHand = (int) ($available[$productId] ?? 0);

                    if ($moving > $onHand) {
                        $name = Product::query()->whereKey($productId)->value('name') ?? ('#'.$productId);

                        throw new RuntimeException(sprintf(
                            'Sisa + reject untuk %s (%d) melebihi stok gerobak yang tinggal %d. Mungkin gerobak ini sudah ditutup barista.',
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

                    SettlementLine::query()
                        ->where('settlement_id', $locked->id)
                        ->where('product_id', $productId)
                        ->update([
                            'qty_wasted' => $qty['rejected'],
                            'qty_remaining' => $qty['returned'],
                        ]);
                }
            }

            $locked->status = 'RECONCILED';
            $locked->reconciled_by = $finance->id;
            $locked->reconciled_at = now();

            if ($note !== null && trim($note) !== '') {
                $locked->variance_reason = trim(($locked->variance_reason ? $locked->variance_reason.' — ' : '').$note);
            }

            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
