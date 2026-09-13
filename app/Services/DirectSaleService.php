<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\DirectSale;
use App\Models\DirectSaleLine;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only writer of `direct_sales`. Records cups leaving a cart because a walk-in buyer paid for
 * them at the kitchen/office, entered by Finance or Administrator — never by a mobile app user or
 * the STAFF role. Mirrors SaleService closely; see that class's docblock for the shared reasoning
 * (stock must cover the sale, price pinned at sale time, void through a compensating ledger entry)
 * and the differences noted below.
 *
 * WHY THIS IS NOT A SALE
 * -----------------------
 * The cart the cups come from need not be assigned to anyone today — Finance may pick an idle
 * cart sitting at the kitchen just as freely as one a rider has mangkal'd in front of the office
 * with. There is no absen gate (Finance is not clocking in to sell), no StaffAssignment
 * requirement, and no suspect-quantity flag (that rule was about a rider's own reported
 * sale pattern out in the field, which does not apply to Finance recording a walk-in purchase in
 * front of them). The stock mechanism is otherwise identical: the cups still leave a cart's stock
 * through the same append-only ledger.
 */
class DirectSaleService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     */
    public function record(
        User $actor,
        int $cartId,
        array $lines,
        string $paymentMethod = 'cash',
        ?string $note = null,
    ): DirectSale {
        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE], true)) {
            throw new RuntimeException('Hanya Finance atau Administrator yang mencatat penjualan langsung kantor.');
        }

        $merged = $this->mergeLines($lines);

        if ($merged === []) {
            throw new RuntimeException('Tidak ada cups yang dicatat.');
        }

        $cart = Cart::query()->find($cartId);

        if (! $cart || $cart->status !== 'active') {
            throw new RuntimeException('Gerobak tidak ditemukan atau sedang tidak aktif.');
        }

        return DB::transaction(function () use ($actor, $cart, $merged, $paymentMethod, $note): DirectSale {
            $productIds = array_keys($merged);

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

            $directSale = DirectSale::query()->create([
                'uuid' => (string) Str::uuid(),
                'cart_id' => $cart->id,
                'recorded_by' => $actor->id,
                // Server clock (R16), same reasoning as Sale::occurred_at.
                'occurred_at' => now(),
                'total_qty' => $totalQty,
                'total_amount_minor' => $totalAmount,
                'payment_method' => $paymentMethod,
                'note' => $note,
            ]);

            foreach ($lineRows as $row) {
                DirectSaleLine::query()->create($row + ['direct_sale_id' => $directSale->id]);

                $this->ledger->post(
                    locationType: StockLedgerService::CART,
                    locationId: $cart->id,
                    productId: $row['product_id'],
                    movementType: MovementType::SALE_OUT,
                    qty: $row['qty'],
                    actorId: $actor->id,
                    kitchenId: (int) $cart->kitchen_id,
                    refType: 'direct_sale',
                    refId: $directSale->id,
                );
            }

            return $directSale->load('lines');
        });
    }

    /**
     * Undoes a direct sale that should never have been recorded. Mirrors SaleService::void()
     * closely, with one deliberate simplification: since only Administrator and Finance ever
     * create or void a direct sale, there is no rider-ownership case and no time window — both
     * may void at any time. The settlement boundary is unchanged: once the cart's cups for that
     * date have already been reconciled, a correction goes through Stock Opname instead, for
     * exactly the reason SaleService::void() explains.
     */
    public function void(DirectSale $directSale, User $actor, string $reason): DirectSale
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE], true)) {
            throw new RuntimeException('Anda tidak berhak membatalkan transaksi ini.');
        }

        return DB::transaction(function () use ($directSale, $actor, $reason): DirectSale {
            $locked = DirectSale::query()->with('lines')->whereKey($directSale->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new RuntimeException('Transaksi ini sudah dibatalkan sebelumnya.');
            }

            $alreadySettled = Settlement::query()
                ->where('cart_id', $locked->cart_id)
                ->whereDate('operating_date', $locked->occurred_at->toDateString())
                ->where('status', 'RECONCILED')
                ->exists();

            if ($alreadySettled) {
                throw new RuntimeException(
                    'Setoran gerobak ini untuk tanggal tersebut sudah direkonsiliasi. Gunakan Stock Opname untuk koreksi, bukan pembatalan transaksi.'
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
                    refType: 'direct_sale_void',
                    refId: $locked->id,
                );
            }

            $locked->voided_at = now();
            $locked->voided_by = $actor->id;
            $locked->void_reason = $reason;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Collapses repeated products and drops empty rows — copied from
     * SaleService::mergeLines(), same reasoning: touching the same tile twice in the panel form
     * is ordinary, and the unique index on (direct_sale_id, product_id) means the alternative
     * would just be an error nobody can act on.
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
