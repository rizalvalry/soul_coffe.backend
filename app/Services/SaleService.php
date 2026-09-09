<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 * 2. **A cart, from today's roster.** The cups come out of a specific cart's stock, so there has
 *    to be one. StaffAssignment is where "which cart today" already lives.
 *
 * 3. **Stock must cover the sale.** The ledger is append-only (R6) and stock is SUM(qty_delta),
 *    so an oversell would leave the cart permanently negative and quietly poison every total
 *    built on it — including the area analytics this feature exists to feed. Refused with the
 *    product name and the number actually available, because "insufficient stock" is useless to
 *    someone holding a queue of customers.
 *
 * 4. **Suspect is a FLAG, never a veto.** A large single transaction is recorded in full and then
 *    reported to Administrator and Finance. This was explicit in the request and it is also the
 *    only defensible design: refusing the sale would punish the honest staff member in a busy
 *    hour, and a dishonest one would simply split it into two taps.
 *
 * The price is pinned per line at sale time, the way R10 pins refill cost: a price change next
 * month must not rewrite what last month earned.
 */
class SaleService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly AttendanceService $attendance,
        private readonly EventPublisher $events,
        private readonly StaffLocationService $locations,
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
        ?Carbon $operatingDate = null,
    ): Sale {
        if ($staff->role !== Role::STAFF) {
            throw new RuntimeException('Hanya staff yang mencatat penjualan gerobak.');
        }

        $date = ($operatingDate ?? Carbon::today())->startOfDay();

        // Rule 1 — see the class docblock.
        if (! $this->attendance->hasClockedIn($staff, $date)) {
            throw new RuntimeException('Absen dulu sebelum mencatat penjualan.');
        }

        // Rule 2.
        $assignment = StaffAssignment::query()
            ->with('cart')
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', $date->toDateString())
            ->first();

        if (! $assignment || ! $assignment->cart) {
            throw new RuntimeException('Anda belum ditugaskan ke gerobak mana pun hari ini.');
        }

        $merged = $this->mergeLines($lines);

        if ($merged === []) {
            throw new RuntimeException('Tidak ada cups yang dicatat.');
        }

        return DB::transaction(function () use ($staff, $assignment, $merged, $uuid, $gps, $paymentMethod, $note, $deviceId, $idempotencyKey, $date): Sale {
            $cart = $assignment->cart;

            // A replay of the same tap returns the sale that already exists rather than selling
            // the cups twice (R14). Checked inside the transaction so two concurrent replays
            // cannot both pass the check.
            $existing = Sale::query()->where('uuid', $uuid)->first();
            if ($existing) {
                return $existing;
            }

            $productIds = array_keys($merged);

            // Rule 3. Locked in ascending product order by the ledger service, which is what
            // keeps two carts selling overlapping products from deadlocking each other.
            $available = $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, $productIds);

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0;
            $lineRows = [];

            foreach ($merged as $productId => $qty) {
                $product = $products[$productId] ?? null;

                if (! $product || ! $product->is_active) {
                    throw new RuntimeException('Produk tidak dikenal atau sudah tidak aktif.');
                }

                $onHand = (int) ($available[$productId] ?? 0);

                if ($qty > $onHand) {
                    throw new RuntimeException(sprintf(
                        'Stok gerobak untuk %s hanya %d %s.',
                        $product->name,
                        $onHand,
                        $product->unit,
                    ));
                }

                $unitPrice = (int) ($product->currentPriceVersion()?->sell_price_minor ?? 0);
                $subtotal = $unitPrice * $qty;

                $totalQty += $qty;
                $totalAmount += $subtotal;

                $lineRows[] = [
                    'product_id' => $productId,
                    'qty' => $qty,
                    'unit_price_minor' => $unitPrice,
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
                'occurred_at' => now(),
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

                $this->ledger->post(
                    locationType: StockLedgerService::CART,
                    locationId: $cart->id,
                    productId: $row['product_id'],
                    movementType: MovementType::SALE_OUT,
                    qty: $row['qty'],
                    actorId: $staff->id,
                    kitchenId: (int) $cart->kitchen_id,
                    refType: 'sale',
                    refId: $sale->id,
                );
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

            return $sale->load('lines');
        });
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
                '%s (gerobak %s) mencatat %d cups dalam satu transaksi — di atas batas %d.',
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
