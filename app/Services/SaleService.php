<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The only writer of `sales`. Records cups leaving a cart because somebody bought them.
 *
 * FOUR RULES, AND WHY EACH ONE IS HERE
 * ------------------------------------
 * 1. **Absen first.** Selling is an act of being on shift, and the shift starts at clock-in. A
 *    sale from someone who never absen would put revenue against a person the attendance sheet
 *    says was not working — two reports contradicting each other about the same morning.
 *
 * 2. **A cart, from today's Add Stock.** The cups leave a specific cart. Current clients
 *    send the cart captured with the sale; the server checks that it is still the Rider's
 *    active assignment. Older clients omit it and use the active cart when submitted.
 *
 * 3. **Stock must cover the sale.** The ledger is append-only (R6) and stock is SUM(qty_delta),
 *    so an oversell would leave the cart permanently negative and quietly poison every total
 *    built on it — including the area analytics this feature exists to feed. Refused with the
 *    product name and the number actually available, because "insufficient stock" is useless to
 *    someone holding a queue of customers.
 *
 * 4. **Suspect is a FLAG, never a veto.** A large single transaction is recorded in full and then
 *    reported to Administrator and Finance. This was explicit in the request and it is also the
 *    only defensible design: refusing the sale would punish the honest rider in a busy
 *    hour, and a dishonest one would simply split it into two taps.
 *
 * The price is pinned per line at sale time, the way R10 pins refill cost: a price change next
 * month must not rewrite what last month earned.
 */
class SaleService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly RiderDayGate $dayGate,
        private readonly EventPublisher $events,
        private readonly StaffLocationService $locations,
        private readonly SettlementService $settlements,
        private readonly ProductSellPriceResolver $sellPrices,
    ) {}

    /**
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     * @param  array{lat: float|null, lng: float|null, unavailable: bool}  $gps
     */
    public function record(
        User $staff,
        array $lines,
        string $uuid,
        array $gps = ['lat' => null, 'lng' => null, 'unavailable' => false],
        string $paymentMethod = 'cash',
        ?string $note = null,
        ?string $deviceId = null,
        ?string $idempotencyKey = null,
        ?int $submittedCartId = null,
    ): Sale {
        if ($staff->role !== Role::STAFF) {
            throw new RuntimeException('Hanya rider yang mencatat penjualan Coffee Bike.');
        }

        $date = Carbon::today();

        // A UUID replay is a read of an already accepted sale, including across days.
        $existing = Sale::query()->where('uuid', $uuid)->first();
        if ($existing) {
            abort_unless($existing->staff_id === $staff->id, 403);

            return $existing;
        }

        $this->dayGate->assertReady($staff, $date);

        // Rule 2.
        $assignment = StaffAssignment::query()
            ->with('cart')
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', $date->toDateString())
            ->where('source', 'hand_to_cart')
            ->whereNull('superseded_at')
            ->latest('id')
            ->first();

        if (! $assignment || ! $assignment->cart) {
            if ($submittedCartId !== null) {
                throw new HttpResponseException(response()->json([
                    'code' => 'CART_CHANGED',
                    'message' => 'Coffee Bike kamu sudah berubah. Cek transaksi ini bareng Administrator ya.',
                ], 422));
            }
            throw new HttpResponseException(response()->json([
                'code' => 'NO_CART_TODAY',
                'message' => 'Produksi belum kasih stok ke Coffee Bike kamu hari ini. Minta Produksi Add Stock dulu ya.',
            ], 403));
        }

        if ($submittedCartId !== null && $submittedCartId !== (int) $assignment->cart_id) {
            throw new HttpResponseException(response()->json([
                'code' => 'CART_CHANGED',
                'message' => 'Coffee Bike kamu sudah berubah. Cek transaksi ini bareng Administrator ya.',
            ], 422));
        }

        $merged = $this->mergeLines($lines);

        if ($merged === []) {
            throw new RuntimeException('Tidak ada cups yang dicatat.');
        }

        return DB::transaction(function () use ($staff, $assignment, $merged, $uuid, $gps, $paymentMethod, $note, $deviceId, $idempotencyKey, $date): Sale {
            // First database read in this transaction is a current locking read. A plain UUID
            // lookup before this lock would pin an old REPEATABLE READ stock snapshot.
            $cart = Cart::query()->whereKey($assignment->cart_id)->lockForUpdate()->firstOrFail();
            if (RiderClosing::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->exists()) {
                throw new HttpResponseException(response()->json([
                    'code' => 'CLOSING_DONE',
                    'message' => 'Closing hari ini sudah masuk. Penjualan baru tidak bisa ditambah ya.',
                ], 409));
            }
            $stillAssigned = StaffAssignment::query()->whereKey($assignment->id)
                ->where('source', 'hand_to_cart')->whereNull('superseded_at')
                ->lockForUpdate()->first();
            if (! $stillAssigned) {
                throw new HttpResponseException(response()->json([
                    'code' => 'CART_CHANGED',
                    'message' => 'Coffee Bike kamu sudah berubah. Cek transaksi ini bareng Administrator ya.',
                ], 422));
            }

            // A replay of the same tap returns the sale that already exists rather than selling
            // the cups twice (R14). Checked inside the transaction so two concurrent replays
            // cannot both pass the check.
            $existing = Sale::query()->where('uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->staff_id === $staff->id, 403);

                return $existing;
            }

            $productIds = array_keys($merged);

            // Rule 3. Locked in ascending product order by the ledger service, which is what
            // keeps two carts selling overlapping products from deadlocking each other.
            $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, $productIds);
            // A locking read sees DirectSale and other stock writers that do not take the cart
            // lock, even if this transaction established an earlier consistent-read snapshot.
            $available = StockLedger::query()->where('location_type', StockLedgerService::CART)
                ->where('location_id', $cart->id)->whereIn('product_id', $productIds)
                ->lockForUpdate()->get(['product_id', 'qty_delta'])->groupBy('product_id')
                ->map(fn ($rows): int => (int) $rows->sum('qty_delta'))->all();
            $reconciled = Settlement::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->lockForUpdate()->first(['status'])?->status === 'RECONCILED';
            $uncoveredStock = [];
            foreach ($merged as $productId => $qty) {
                $missing = max(0, $qty - (int) ($available[$productId] ?? 0));
                if ($reconciled && $missing > 0) {
                    $uncoveredStock[$productId] = $missing;
                }
            }
            $stockCorrectionNeeded = $uncoveredStock !== [];

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                // Keep each sold unit stable through the sale-line inserts for payroll cups.
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0;
            $lineRows = [];
            $occurredAt = now();

            foreach ($merged as $productId => $qty) {
                $product = $products[$productId] ?? null;

                if (! $product || ! $product->is_active || ! $product->is_sellable) {
                    throw new RuntimeException('Produk ini bukan produk utama');
                }

                $onHand = (int) ($available[$productId] ?? 0);

                if ($qty > $onHand && ! $reconciled) {
                    throw new RuntimeException(sprintf(
                        'Stok Coffee Bike untuk %s hanya %d %s.',
                        $product->name,
                        $onHand,
                        $product->unit,
                    ));
                }

                $priceVersion = $this->sellPrices->baseVersionForDay($product, $date);
                if ($priceVersion === null) {
                    throw ValidationException::withMessages(['lines' => 'Harga dan HPP produk ini belum tersedia. Minta Finance isi dulu ya.']);
                }
                $unitPrice = $this->sellPrices->priceForCart($product, $cart->id, $date);
                $subtotal = $unitPrice * $qty;

                $totalQty += $qty;
                $totalAmount += $subtotal;

                $lineRows[] = [
                    'product_id' => $productId,
                    'qty' => $qty,
                    'unit_price_minor' => $unitPrice,
                    'unit_cost_minor' => $priceVersion?->cost_price_minor,
                    'subtotal_minor' => $subtotal,
                ];
            }

            // Rule 4 — decided here, applied as a flag on the row below.
            $threshold = (int) config('soul.sale_suspect_qty_threshold', 15);
            $overThreshold = $totalQty > $threshold;
            $isSuspect = $overThreshold && ! $cart->high_volume_zone;

            $sale = Sale::query()->create([
                'uuid' => $uuid,
                'operating_date' => $date->toDateString(),
                'cart_id' => $cart->id,
                'staff_id' => $staff->id,
                'location_id' => $assignment->location_id,
                // Server clock (R16): the analytics compares hours between carts, so a phone's
                // idea of the time cannot be allowed to decide which hour a sale lands in.
                'occurred_at' => $occurredAt,
                'total_qty' => $totalQty,
                'total_amount_minor' => $totalAmount,
                'payment_method' => $paymentMethod,
                'gps_lat' => $gps['lat'] ?? null,
                'gps_lng' => $gps['lng'] ?? null,
                'gps_unavailable' => (bool) ($gps['unavailable'] ?? false),
                'is_suspect' => $isSuspect,
                'suspect_reason' => $isSuspect
                    ? sprintf('%d cups dalam satu transaksi (batas %d).', $totalQty, $threshold)
                    : null,
                'note' => $note,
                'device_id' => $deviceId,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($lineRows as $row) {
                SaleLine::query()->create($row + ['sale_id' => $sale->id]);

                $stockPosted = $row['qty'] - ($uncoveredStock[$row['product_id']] ?? 0);
                if ($stockPosted > 0) {
                    $this->ledger->post(
                        locationType: StockLedgerService::CART,
                        locationId: $cart->id,
                        productId: $row['product_id'],
                        movementType: MovementType::SALE_OUT,
                        qty: $stockPosted,
                        actorId: $staff->id,
                        kitchenId: (int) $cart->kitchen_id,
                        refType: 'sale',
                        refId: $sale->id,
                    );
                }
            }

            if ($stockCorrectionNeeded) {
                // Approval already disposed the theoretical stock. A late SALE_OUT would make
                // the append-only ledger negative or move the same cups twice.
                AuditLog::query()->create([
                    'actor_id' => $staff->id, 'actor_role' => $staff->role->value,
                    'action' => 'sale.late_after_settlement', 'subject_type' => Sale::class,
                    'subject_id' => $sale->id, 'before_json' => null,
                    'after_json' => ['cart_id' => $cart->id, 'operating_date' => $date->toDateString(),
                        'stock_movement' => 'partially_or_already_disposed',
                        'uncovered_qty_by_product' => $uncoveredStock],
                    'ip' => request()->ip(), 'device_id' => $deviceId,
                ]);
            }

            // A coordinate captured at the moment of a sale is the strongest position this
            // system ever gets: it is tied to something that demonstrably happened. Recording it
            // in the trail means the area × hour analysis still works for a phone that has
            // background location switched off, and it can never fail the sale — that is why it
            // is a plain insert of data already validated above, not a second decision.
            if ($gps['lat'] !== null && $gps['lng'] !== null) {
                $this->locations->recordFromAction(
                    staff: $staff,
                    lat: (float) $gps['lat'],
                    lng: (float) $gps['lng'],
                    source: 'sale',
                    cartId: $cart->id,
                    locationId: $assignment->location_id,
                    deviceId: $deviceId,
                );
            }

            if ($isSuspect) {
                $this->notifySuspect($sale, $staff, $cart->code, $totalQty, $threshold);
            }

            $this->events->publish(
                type: 'SaleRecorded',
                title: 'Penjualan tercatat',
                body: sprintf('Penjualan di Coffee Bike %s tercatat.', $cart->code),
                channels: ['role.FINANCE', 'role.MANAGEMENT'],
                context: [
                    'cart_id' => $sale->cart_id,
                    'operating_date' => $sale->operating_date->toDateString(),
                    'source_id' => $sale->id,
                ],
            );

            $this->settlements->markExpectedStale($cart->id, $date, $staff, $totalAmount, $stockCorrectionNeeded);

            return $sale->load('lines');
        });
    }

    /**
     * Undoes a sale that should never have been recorded: the wrong product, the wrong quantity,
     * a double tap on a queue that fought back.
     *
     * VOIDED, NOT DELETED — see the migration that added these columns. The row stays exactly
     * where it was; every reader of `sales` (SalesActivityService, SettlementService,
     * StaffLocationService, SaleController, SaleResource) excludes it by checking `voided_at`,
     * the same predicate everywhere so a void can never half-apply.
     *
     * WHO MAY VOID, AND UNTIL WHEN
     * ----------------------------
     * The staff member who made the sale may undo it themselves, but only within a short window
     * (`soul.sale_void_window_minutes`) — long enough to correct a mis-tap while the customer is
     * still standing there, short enough that it cannot be used to quietly erase a shift's
     * revenue after the fact. Administrator and Finance may void at any time, because a mistake
     * found later — during a settlement review, say — still needs a way back.
     *
     * WHY THIS REFUSES ONCE THE DAY IS SETTLED
     * -----------------------------------------
     * A RECONCILED settlement already moved the cart's leftover cups through the ledger — some
     * back to the kitchen, some written off — measured against live stock at that moment (see
     * SettlementService). Giving cups back to a cart that has since been emptied and reconciled
     * would resurrect stock nobody is expecting and nobody will notice landed there. Once a day
     * is settled, a correction goes through a stock opname instead, which is exactly the tool
     * built for "the ledger and reality disagree, after the fact".
     */
    public function void(Sale $sale, User $actor, string $reason): Sale
    {
        if ($actor->role === Role::STAFF) {
            $this->dayGate->assertReady($actor);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($sale, $actor, $reason): Sale {
            $locked = Sale::query()->with('lines')->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new RuntimeException('Transaksi ini sudah dibatalkan sebelumnya.');
            }

            $this->assertMayVoid($locked, $actor);

            Cart::query()->whereKey($locked->cart_id)->lockForUpdate()->firstOrFail();

            if (RiderClosing::query()->where('cart_id', $locked->cart_id)
                ->whereDate('operating_date', $locked->operating_date)->exists()) {
                throw new RuntimeException('Coffee Bike sudah Closing. Koreksi angkanya lewat Closing Finance ya.');
            }

            $alreadySettled = Settlement::query()
                ->where('cart_id', $locked->cart_id)
                ->whereDate('operating_date', $locked->operating_date)
                ->where('status', 'RECONCILED')
                ->exists();

            if ($alreadySettled) {
                throw new RuntimeException(
                    'Setoran Coffee Bike ini untuk tanggal tersebut sudah direkonsiliasi. Gunakan Stock Opname untuk koreksi, bukan pembatalan transaksi.'
                );
            }

            $cart = $locked->cart;

            foreach ($locked->lines as $line) {
                $this->ledger->post(
                    locationType: StockLedgerService::CART,
                    locationId: $locked->cart_id,
                    productId: $line->product_id,
                    movementType: MovementType::SALE_VOID_IN,
                    qty: $line->qty,
                    actorId: $actor->id,
                    kitchenId: (int) $cart->kitchen_id,
                    refType: 'sale_void',
                    refId: $locked->id,
                );
            }

            $locked->voided_at = now();
            $locked->voided_by = $actor->id;
            $locked->void_reason = $reason;
            $locked->save();

            $this->notifyVoided($locked, $actor);
            $this->settlements->markExpectedStale($locked->cart_id, $locked->operating_date, $actor, -$locked->total_amount_minor);

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Rule 2 of `void()` — see its docblock. Administrator and Finance pass unconditionally; a
     * staff member must be the one who made the sale, and only inside the configured window.
     */
    private function assertMayVoid(Sale $sale, User $actor): void
    {
        if (PermissionMatrix::can($actor, PanelModule::SALE_CORRECTIONS, 'edit')) {
            return;
        }

        if ($actor->role !== Role::STAFF || $actor->id !== $sale->staff_id) {
            abort(403, 'Kamu tidak punya akses buat membatalkan transaksi ini.');
        }

        $windowMinutes = (int) config('soul.sale_void_window_minutes', 10);

        if ($sale->occurred_at->lt(now()->subMinutes($windowMinutes))) {
            throw new RuntimeException(sprintf(
                'Pembatalan hanya bisa dilakukan dalam %d menit setelah transaksi. Setelah itu, minta Administrator atau Finance membatalkannya.',
                $windowMinutes,
            ));
        }
    }

    /**
     * Administrator and Finance are told every void, regardless of who made it — the same
     * audience as the suspect flag, and for the same reason: a reversed sale is exactly the kind
     * of thing whoever reconciles the day needs visibility into.
     */
    private function notifyVoided(Sale $sale, User $actor): void
    {
        $recipients = User::query()
            ->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE])
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->events->publish(
            'SaleVoided',
            'Transaksi dibatalkan',
            sprintf(
                '%s membatalkan transaksi %d cups di Coffee Bike %s. Alasan: %s',
                $actor->name,
                $sale->total_qty,
                $sale->cart?->code,
                $sale->void_reason,
            ),
            ['role.ADMINISTRATOR', 'role.FINANCE', 'role.MANAGEMENT'],
            $recipients,
            context: [
                'cart_id' => $sale->cart_id,
                'operating_date' => $sale->operating_date->toDateString(),
                'source_id' => $sale->id,
            ],
        );
    }

    /**
     * Tells Administrator and Finance, and nobody else.
     *
     * Deliberately NOT `role.STAFF`: the other staff members have no business seeing that a
     * colleague's transaction was flagged, and a suspicion broadcast to the whole team is an
     * accusation. The staff member who made the sale is not told either — a flag is a prompt for
     * someone to look, not a verdict to deliver.
     */
    private function notifySuspect(Sale $sale, User $staff, string $cartCode, int $totalQty, int $threshold): void
    {
        $recipients = User::query()
            ->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE])
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->events->publish(
            'SaleFlaggedSuspect',
            'Transaksi perlu ditinjau',
            sprintf(
                '%s (Coffee Bike %s) mencatat %d cups dalam satu transaksi — di atas batas %d.',
                $staff->name,
                $cartCode,
                $totalQty,
                $threshold,
            ),
            ['role.ADMINISTRATOR', 'role.FINANCE'],
            $recipients,
        );
    }

    /**
     * Collapses repeated products and drops empty rows.
     *
     * The app sends one row per tile the operator touched, and touching the same tile twice is
     * ordinary. Merging here rather than rejecting keeps the phone simple, and the unique index
     * on (sale_id, product_id) means the alternative would just be an error nobody can act on.
     *
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     * @return array<int,int> product_id => qty
     */
    private function mergeLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);

            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $qty;
        }

        return $merged;
    }
}
