<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\DirectSale;
use App\Models\DirectSaleLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        private readonly JournalService $journals,
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
        if (! PermissionMatrix::can($actor, PanelModule::DIRECT_SALE_ACTIONS, 'edit')) {
            throw new RuntimeException('Hanya Finance atau Administrator yang mencatat penjualan langsung kantor.');
        }

        $merged = $this->mergeLines($lines);

        if ($merged === []) {
            throw new RuntimeException('Tidak ada cups yang dicatat.');
        }

        $cart = Cart::query()->find($cartId);

        if (! $cart || $cart->status !== 'active') {
            throw new RuntimeException('Coffee Bike tidak ditemukan atau sedang tidak aktif.');
        }

        return DB::transaction(function () use ($actor, $cart, $merged, $paymentMethod, $note): DirectSale {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $occurredAt = now();
            $this->assertNotClosed($cart->id, $occurredAt->toDateString());
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
                        'Stok Coffee Bike untuk %s hanya %d %s.',
                        $product->name,
                        $onHand,
                        $product->unit,
                    ));
                }

                $priceVersion = $product->priceVersionAt($occurredAt);
                $unitPrice = (int) ($priceVersion?->sell_price_minor ?? 0);
                if ($unitPrice <= 0) {
                    throw new RuntimeException('Harga jual produk belum tersedia untuk penjualan langsung.');
                }
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

            $directSale = DirectSale::query()->create([
                'uuid' => (string) Str::uuid(),
                'cart_id' => $cart->id,
                'recorded_by' => $actor->id,
                // Server clock (R16), same reasoning as Sale::occurred_at.
                'occurred_at' => $occurredAt,
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

            if ($totalAmount > 0) {
                // Serialize the tax version read with Management's setting change.
                Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                $cashCode = match ($paymentMethod) {
                    'cash' => '1-1100',
                    'qris' => '1-1400',
                    'transfer' => '1-1400',
                    default => throw new RuntimeException('Metode pembayaran tidak valid.'),
                };
                $accountIds = Account::query()->whereIn('code', [$cashCode, '4-1200', '2-1500'])
                    ->pluck('id', 'code');
                $split = app(TaxService::class)->splitPbjt($totalAmount, $occurredAt->toDateString());
                $revenueLines = [
                    ['account_id' => $accountIds[$cashCode], 'debit_minor' => $totalAmount, 'credit_minor' => 0],
                ];
                if ($split['revenue'] > 0) {
                    $revenueLines[] = ['account_id' => $accountIds['4-1200'], 'debit_minor' => 0, 'credit_minor' => $split['revenue']];
                }
                if ($split['tax'] > 0) {
                    $revenueLines[] = ['account_id' => $accountIds['2-1500'], 'debit_minor' => 0, 'credit_minor' => $split['tax']];
                }
                $this->journals->post([
                    'entry_date' => $occurredAt->toDateString(),
                    'description' => 'Penjualan langsung kantor #'.$directSale->id,
                    'source_type' => 'direct_sale', 'source_id' => $directSale->id,
                    'purpose' => 'DIRECT_SALE', 'posted_by' => $actor->id,
                ], $revenueLines);
            }

            AuditLog::recordAction($actor, 'direct_sale.created', $directSale, null, [
                'cart_id' => $cart->id, 'payment_method' => $paymentMethod,
                'total_amount_minor' => $totalAmount,
            ]);

            return $directSale->load('lines');
        });
    }

    /**
     * Undoes a direct sale that should never have been recorded. Mirrors SaleService::void()
     * closely, with one deliberate simplification: since only Administrator and Finance ever
     * create or void a direct sale, there is no rider-ownership case. A void is refused once
     * Closing exists for the sale day or the current day: its stock reversal posts now and must
     * not alter either snapshot. The settlement boundary is unchanged: once the cart's cups for
     * the sale date have been reconciled, a correction goes through Stock Opname instead.
     */
    public function void(DirectSale $directSale, User $actor, string $reason): DirectSale
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        if (! PermissionMatrix::can($actor, PanelModule::DIRECT_SALE_ACTIONS, 'edit')) {
            throw new RuntimeException('Anda tidak berhak membatalkan transaksi ini.');
        }

        return DB::transaction(function () use ($directSale, $actor, $reason): DirectSale {
            Cart::query()->whereKey($directSale->cart_id)->lockForUpdate()->firstOrFail();
            $locked = DirectSale::query()->with('lines')->whereKey($directSale->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new RuntimeException('Transaksi ini sudah dibatalkan sebelumnya.');
            }

            $voidMessage = 'Penjualan langsung ini nggak bisa dibatalkan karena Coffee Bike sudah Closing.';
            $this->assertNotClosed($locked->cart_id, $locked->occurred_at->toDateString(), $voidMessage);
            // A void posts stock back now, so today's Closing must also stay unchanged.
            if ($locked->occurred_at->toDateString() !== today()->toDateString()) {
                $this->assertNotClosed($locked->cart_id, today()->toDateString(), $voidMessage);
            }

            $alreadySettled = Settlement::query()
                ->where('cart_id', $locked->cart_id)
                ->whereDate('operating_date', $locked->occurred_at->toDateString())
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
                    refType: 'direct_sale_void',
                    refId: $locked->id,
                );
            }

            foreach (['DIRECT_SALE', 'DIRECT_SALE_COGS'] as $purpose) {
                $entry = JournalEntry::query()->where('source_type', 'direct_sale')
                    ->where('source_id', $locked->id)->where('purpose', $purpose)->first();
                if ($entry) {
                    $this->journals->reverse($entry, $reason);
                }
            }

            $locked->voided_at = now();
            $locked->voided_by = $actor->id;
            $locked->void_reason = $reason;
            $locked->save();

            AuditLog::recordAction($actor, 'direct_sale.voided', $locked,
                ['voided_at' => null], ['voided_at' => $locked->voided_at?->toIso8601String(), 'reason' => $reason]);

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

    private function assertNotClosed(int $cartId, string $date, string $message = 'Coffee Bike ini sudah Closing hari ini. Pilih Coffee Bike lain.'): void
    {
        if (RiderClosing::query()->where('cart_id', $cartId)
            ->where('operating_date', $date)->exists()) {
            throw new HttpResponseException(response()->json([
                'message' => $message,
            ], 409));
        }
    }
}
