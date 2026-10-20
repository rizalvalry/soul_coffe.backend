<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderPayment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Purchase orders — how raw material stock enters the system traceably, with a real cost.
 * Mirrors StockOpnameService's shape: create a record first, decide on it later, lock the row
 * before any write that touches the ledger.
 *
 * WHO MAY DO THIS
 * ---------------
 * Administrator or Finance only, the same audience every other money-adjacent write in this
 * system already answers to (StockOpnameService, SaleService::assertMayVoid).
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly RecipeService $recipes,
        private readonly JournalService $journals,
    ) {}

    /**
     * @param  array<int, array{raw_material_id: int, qty_ordered: int, unit_cost_minor: int}>  $lines
     */
    public function create(int $supplierId, int $kitchenId, array $lines, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        if ($lines === []) {
            throw new RuntimeException('Purchase order harus punya minimal satu baris bahan baku.');
        }

        $seen = [];
        foreach ($lines as $line) {
            $rawMaterialId = (int) ($line['raw_material_id'] ?? 0);
            $qtyOrdered = (int) ($line['qty_ordered'] ?? 0);
            $unitCostMinor = (int) ($line['unit_cost_minor'] ?? 0);

            if ($rawMaterialId <= 0) {
                throw new RuntimeException('Bahan baku pada purchase order tidak valid.');
            }

            if ($qtyOrdered <= 0) {
                throw new RuntimeException('Jumlah pesan harus lebih dari nol.');
            }

            if ($unitCostMinor <= 0) {
                throw new RuntimeException('Harga satuan harus lebih dari nol.');
            }

            if (isset($seen[$rawMaterialId])) {
                throw new RuntimeException('Bahan baku yang sama tidak boleh muncul dua kali dalam satu purchase order.');
            }

            $seen[$rawMaterialId] = true;
        }

        return DB::transaction(function () use ($supplierId, $kitchenId, $lines, $actor): PurchaseOrder {
            $po = PurchaseOrder::query()->create([
                'uuid' => (string) Str::uuid(),
                'supplier_id' => $supplierId,
                'kitchen_id' => $kitchenId,
                'status' => PurchaseOrderStatus::DRAFT,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $po->id,
                    'raw_material_id' => (int) $line['raw_material_id'],
                    'qty_ordered' => (int) $line['qty_ordered'],
                    'unit_cost_minor' => (int) $line['unit_cost_minor'],
                ]);
            }

            AuditLog::recordAction($actor, 'purchase_order.created', $po, null, [
                'supplier_id' => $supplierId, 'kitchen_id' => $kitchenId,
                'lines' => $po->lines()->get()->map(fn ($line): array => $line->only([
                    'raw_material_id', 'qty_ordered', 'unit_cost_minor',
                ]))->all(),
            ]);

            return $po->load('lines');
        });
    }

    public function markOrdered(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDraft()) {
                throw new RuntimeException('Hanya draf yang bisa ditandai dipesan.');
            }

            $locked->status = PurchaseOrderStatus::ORDERED;
            $locked->ordered_at = now();
            $locked->save();

            AuditLog::recordAction($actor, 'purchase_order.ordered', $locked,
                ['status' => 'DRAFT'], ['status' => 'ORDERED']);

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Posts the actual receipt to the stock ledger. Supports receiving less than what was
     * ordered per line; the PO as a whole still becomes RECEIVED exactly once — there is no
     * partial/second receive. A line with no explicit `$receivedQuantities` entry defaults to its
     * full `qty_ordered`.
     *
     * @param  array<int,int|string>  $receivedQuantities  raw_material_id => qty actually delivered
     */
    public function receive(PurchaseOrder $po, array $receivedQuantities, User $actor, string $paymentMethod = 'CREDIT', ?int $cashAccountId = null): PurchaseOrder
    {
        $this->assertMayOperate($actor);
        if (! in_array($paymentMethod, ['DIRECT', 'CREDIT'], true) || ($paymentMethod === 'DIRECT') !== ($cashAccountId !== null)) {
            throw ValidationException::withMessages(['payment_method' => 'Pilih bayar langsung dengan akun kas/bank, atau kredit.']);
        }
        Validator::make(['received_quantities' => $receivedQuantities], [
            'received_quantities' => ['array'],
            'received_quantities.*' => ['integer'],
        ], ['received_quantities.*.integer' => 'Jumlah diterima harus angka bulat ya.'])->validate();

        $received = DB::transaction(function () use ($po, $receivedQuantities, $actor, $paymentMethod, $cashAccountId): PurchaseOrder {
            $locked = PurchaseOrder::query()->with('lines')->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isReceived()) {
                throw new RuntimeException('Purchase order ini sudah diterima.');
            }

            if (! $locked->status->isOrdered()) {
                throw new RuntimeException('Tandai purchase order ini sudah dipesan dulu ya.');
            }

            $amount = 0;
            foreach ($locked->lines as $line) {
                $qtyReceived = array_key_exists($line->raw_material_id, $receivedQuantities)
                    ? (int) $receivedQuantities[$line->raw_material_id]
                    : (int) $line->qty_ordered;

                if ($qtyReceived < 0) {
                    throw new RuntimeException('Jumlah diterima tidak boleh negatif.');
                }

                if ($qtyReceived > (int) $line->qty_ordered || ($qtyReceived > 0 && (int) $line->unit_cost_minor > intdiv(1000000000000 - $amount, $qtyReceived))) {
                    throw ValidationException::withMessages(['lines' => 'Jumlah diterima atau total nilai PO tidak valid.']);
                }
                $lineAmount = $qtyReceived * (int) $line->unit_cost_minor;
                $amount += $lineAmount;

                $line->update(['qty_received' => $qtyReceived]);

                if ($qtyReceived > 0) {
                    $this->ledger->post(
                        locationType: StockLedgerService::RAW_MATERIAL_STORE,
                        locationId: $locked->kitchen_id,
                        productId: null,
                        movementType: MovementType::PURCHASE_IN,
                        qty: $qtyReceived,
                        actorId: $actor->id,
                        kitchenId: $locked->kitchen_id,
                        refType: 'purchase_order',
                        refId: $locked->id,
                        rawMaterialId: $line->raw_material_id,
                        costMinor: $lineAmount,
                    );
                }
            }

            if ($amount === 0) {
                throw ValidationException::withMessages(['lines' => 'Isi minimal satu jumlah bahan baku yang diterima.']);
            }

            $cash = $paymentMethod === 'DIRECT' ? $this->cashAccount($cashAccountId) : null;

            $locked->status = PurchaseOrderStatus::RECEIVED;
            $locked->received_by = $actor->id;
            $locked->received_at = now();
            $locked->payment_method = $paymentMethod;
            $locked->cash_account_id = $cash?->id;
            $locked->save();

            $this->postReceiptJournal($locked, $amount, $actor->id);

            AuditLog::recordAction($actor, 'purchase_order.received', $locked,
                ['status' => 'ORDERED'], ['status' => 'RECEIVED', 'amount_minor' => $amount,
                    'payment_method' => $paymentMethod, 'lines' => $locked->lines->map(fn ($line): array => [
                        'raw_material_id' => $line->raw_material_id, 'qty_received' => $line->qty_received,
                    ])->all()]);

            return $locked->fresh(['lines']);
        });

        // Best-effort: the stock movement just committed is the source of truth. A failure here
        // must not roll back a receipt that has already happened in the real kitchen — it would
        // only leave `computed_cost_minor` stale, which the next receive of this raw material
        // corrects anyway.
        try {
            $rawMaterialIds = $received->lines
                ->filter(fn (PurchaseOrderLine $line): bool => (int) $line->qty_received > 0)
                ->pluck('raw_material_id')
                ->all();

            $this->recipes->refreshCostsForRawMaterials($rawMaterialIds, $received->kitchen_id);
        } catch (\Throwable $e) {
            Log::warning('Recipe cost refresh failed after purchase order receipt.', [
                'purchase_order_id' => $received->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $received;
    }

    public function payDebt(PurchaseOrder $po, User $actor, array $input): PurchaseOrderPayment
    {
        $this->assertMayOperate($actor);
        if (! PermissionMatrix::can($actor, PanelModule::PURCHASE_ORDERS, 'edit')) {
            abort(403);
        }
        $data = Validator::make($input, [
            'uuid' => ['required', 'uuid'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
        ])->validate();

        return DB::transaction(function () use ($po, $actor, $data): PurchaseOrderPayment {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $existing = PurchaseOrderPayment::query()->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach (['purchase_order_id' => $locked->id, ...$data] as $field => $value) {
                    $old = $field === 'paid_on' ? $existing->paid_on->toDateString() : $existing->$field;
                    if ((string) $old !== (string) $value) {
                        throw ValidationException::withMessages(['uuid' => 'Kunci pembayaran ini sudah dipakai untuk data lain.']);
                    }
                }

                return $existing;
            }

            if (! $locked->status->isReceived() || $locked->payment_method !== 'CREDIT') {
                throw ValidationException::withMessages(['purchase_order' => 'Hanya PO kredit yang sudah diterima yang bisa dibayar.']);
            }
            if ($locked->received_at === null || $data['paid_on'] < $locked->received_at->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'Tanggal bayar tidak boleh sebelum PO diterima ya.']);
            }
            // JournalService::reverse() locks this same row, so reversal cannot slip between
            // checking the outstanding balance and posting the payment journal.
            $receipt = $locked->receiptJournal()->lockForUpdate()->first();
            if (! $receipt || $receipt->reversal()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'Jurnal penerimaan PO ini sudah dibalik atau belum ada.']);
            }
            if ((int) $data['amount_minor'] > $locked->unpaidMinor()) {
                throw ValidationException::withMessages(['amount_minor' => 'Nominal melebihi sisa hutang PO.']);
            }
            $cash = $this->cashAccount((int) $data['cash_account_id']);
            $liability = Account::query()->where('code', '2-1400')->firstOrFail();
            $payment = PurchaseOrderPayment::query()->create([
                ...$data, 'purchase_order_id' => $locked->id, 'paid_by' => $actor->id,
            ]);
            $amount = (int) $data['amount_minor'];
            $this->journals->post([
                'entry_date' => $data['paid_on'],
                'description' => 'Bayar hutang supplier PO '.$locked->id,
                'source_type' => 'purchase_order_payment', 'source_id' => $payment->id,
                'purpose' => 'PAYMENT', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $liability->id, 'debit_minor' => $amount, 'credit_minor' => 0],
                ['account_id' => $cash->account_id, 'debit_minor' => 0, 'credit_minor' => $amount],
            ]);

            AuditLog::recordAction($actor, 'purchase_order.paid', $payment, null, [
                'purchase_order_id' => $locked->id, 'paid_on' => $data['paid_on'],
                'amount_minor' => $amount, 'cash_account_id' => $cash->id,
            ]);

            return $payment;
        }, 3);
    }

    private function postReceiptJournal(PurchaseOrder $po, int $amount, ?int $actorId): JournalEntry
    {
        $inventory = Account::query()->where('code', '1-1700')->firstOrFail();
        $creditId = $po->payment_method === 'CREDIT'
            ? Account::query()->where('code', '2-1400')->firstOrFail()->id
            : $this->cashAccount($po->cash_account_id)->account_id;

        return $this->journals->post([
            'entry_date' => $po->received_at->toDateString(),
            'description' => 'Penerimaan bahan baku PO '.$po->id,
            'source_type' => 'purchase_order', 'source_id' => $po->id,
            'purpose' => 'RECEIPT', 'posted_by' => $actorId,
        ], [
            ['account_id' => $inventory->id, 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => $creditId, 'debit_minor' => 0, 'credit_minor' => $amount],
        ]);
    }

    private function cashAccount(?int $id): CashAccount
    {
        $cash = CashAccount::query()->with('account')->whereKey($id)->lockForUpdate()->first();
        if (! $cash || ! $cash->is_active || ! $cash->account->is_active
            || ! in_array($cash->account->code, ['1-1100', '1-1200', '1-1300'], true)) {
            throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas atau bank yang aktif.']);
        }

        return $cash;
    }

    /**
     * Refused once RECEIVED — a received PO has already posted to the ledger, and correcting a
     * posted fact is a Stock Opname's job, not a cancel's (the same sale-void-vs-settlement
     * boundary SaleService/StockOpnameService already draw).
     */
    public function cancel(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($po): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isReceived()) {
                throw new RuntimeException('Purchase order yang sudah diterima tidak bisa dibatalkan — gunakan Stock Opname untuk koreksi.');
            }

            $locked->status = PurchaseOrderStatus::CANCELLED;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    private function assertMayOperate(User $actor): void
    {
        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT], true)) {
            abort(403);
        }
    }
}
