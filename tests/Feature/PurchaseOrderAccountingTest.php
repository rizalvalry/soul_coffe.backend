<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseOrderAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private PurchaseOrderService $service;

    private CashAccount $cash;

    private CentralKitchen $kitchen;

    private Supplier $supplier;

    private RawMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::PURCHASE_ORDERS, ['view', 'edit']);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->service = app(PurchaseOrderService::class);
        $this->cash = CashAccount::where('account_id', Account::where('code', '1-1100')->firstOrFail()->id)->firstOrFail();
        $this->kitchen = CentralKitchen::create(['name' => 'Dapur Uji', 'address' => 'Jl. Uji', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-UJI', 'name' => 'Supplier Uji', 'is_active' => true]);
        $this->material = RawMaterial::create(['code' => 'BAHAN-UJI', 'name' => 'Bahan Uji', 'unit' => 'g', 'is_active' => true, 'sort_order' => 1]);
    }

    private function order(): PurchaseOrder
    {
        $po = $this->service->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->material->id, 'qty_ordered' => 10, 'unit_cost_minor' => 2500],
        ], $this->finance);

        return $this->service->markOrdered($po, $this->finance);
    }

    private function assertEntry(JournalEntry $entry, string $debitCode, string $creditCode, int $amount): void
    {
        $rows = $entry->lines()->with('account')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($debitCode, $rows->firstWhere('debit_minor', $amount)->account->code);
        $this->assertSame($creditCode, $rows->firstWhere('credit_minor', $amount)->account->code);
        $this->assertSame($amount, (int) $rows->sum('debit_minor'));
        $this->assertSame($amount, (int) $rows->sum('credit_minor'));
    }

    public function test_direct_and_credit_receipts_post_partial_integer_amounts_once(): void
    {
        $direct = $this->service->receive($this->order(), [$this->material->id => 7], $this->finance, 'DIRECT', $this->cash->id);
        foreach (['purchase_order.created', 'purchase_order.ordered', 'purchase_order.received'] as $action) {
            $this->assertSame(1, AuditLog::query()->where('action', $action)
                ->where('subject_id', $direct->id)->count());
        }
        $entry = JournalEntry::where('source_type', 'purchase_order')->where('source_id', $direct->id)->firstOrFail();
        $this->assertEntry($entry, '1-1700', '1-1100', 17500);
        $this->assertSame(17500, (int) StockLedger::where('ref_type', 'purchase_order')->where('ref_id', $direct->id)->sum('cost_minor'));

        $credit = $this->service->receive($this->order(), [], $this->finance, 'CREDIT');
        $this->assertEntry(JournalEntry::where('source_type', 'purchase_order')->where('source_id', $credit->id)->firstOrFail(), '1-1700', '2-1400', 25000);
        try {
            $this->service->receive($credit, [], $this->finance);
            $this->fail('Second receive accepted');
        } catch (\RuntimeException) {
            $this->assertSame(1, JournalEntry::where('source_type', 'purchase_order')->where('source_id', $credit->id)->count());
        }
    }

    public function test_partial_payments_are_idempotent_and_cannot_exceed_remaining_debt(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        $input = ['uuid' => '027da3fc-df49-4146-9840-1f1d82e7462d', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'amount_minor' => 10000];
        $first = $this->service->payDebt($po, $this->finance, $input);
        $this->assertSame(1, AuditLog::query()->where('action', 'purchase_order.paid')
            ->where('subject_id', $first->id)->count());
        $this->assertSame($first->id, $this->service->payDebt($po, $this->finance, $input)->id);
        $this->assertEntry(JournalEntry::where('source_type', 'purchase_order_payment')->where('source_id', $first->id)->firstOrFail(), '2-1400', '1-1100', 10000);
        $this->assertSame(15000, $po->unpaidMinor());
        try {
            $this->service->payDebt($po, $this->finance, [...$input, 'uuid' => 'ee92d7f3-e52e-4804-9339-349321098abc', 'amount_minor' => 15001]);
            $this->fail('Overpayment accepted');
        } catch (ValidationException) {
            $this->assertSame(1, PurchaseOrderPayment::count());
        }
        $this->service->payDebt($po, $this->finance, [...$input, 'uuid' => 'ee92d7f3-e52e-4804-9339-349321098abc', 'amount_minor' => 15000]);
        $this->assertSame(0, $po->unpaidMinor());
    }

    public function test_wrong_role_payment_is_403(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        try {
            $this->service->payDebt($po, User::factory()->role(Role::BARISTA)->create(), [
                'uuid' => '755d55f2-5e43-41de-adac-375f1b8db5e9', 'paid_on' => today()->toDateString(),
                'cash_account_id' => $this->cash->id, 'amount_minor' => 1000,
            ]);
            $this->fail('Wrong role accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(0, PurchaseOrderPayment::count());
        }
    }

    public function test_closed_period_rolls_back_receipt_stock_and_payment(): void
    {
        $date = today();
        $po = $this->order();
        AccountingPeriod::create(['year' => $date->year, 'month' => $date->month, 'status' => 'CLOSED']);
        try {
            $this->service->receive($po, [], $this->finance);
            $this->fail('Closed period receipt accepted');
        } catch (ValidationException) {
            $this->assertSame('ORDERED', $po->fresh()->status->value);
            $this->assertSame(0, StockLedger::where('ref_type', 'purchase_order')->where('ref_id', $po->id)->count());
        }
        AccountingPeriod::where('year', $date->year)->where('month', $date->month)->update(['status' => 'OPEN']);
        $po = $this->service->receive($po, [], $this->finance);
        AccountingPeriod::where('year', $date->year)->where('month', $date->month)->update(['status' => 'CLOSED']);
        try {
            $this->service->payDebt($po, $this->finance, ['uuid' => '271f377a-315e-47e9-8d55-7012fec43340',
                'paid_on' => $date->toDateString(), 'cash_account_id' => $this->cash->id, 'amount_minor' => 1000]);
            $this->fail('Closed period payment accepted');
        } catch (ValidationException) {
            $this->assertSame(0, PurchaseOrderPayment::count());
        }
    }

    public function test_payment_date_cannot_precede_receipt(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        try {
            $this->service->payDebt($po, $this->finance, [
                'uuid' => '2fd277a1-3c43-45f2-904a-46d6cb01dd46', 'paid_on' => today()->subDay()->toDateString(),
                'cash_account_id' => $this->cash->id, 'amount_minor' => 1000,
            ]);
            $this->fail('Payment before receipt accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paid_on', $exception->errors());
            $this->assertSame(0, PurchaseOrderPayment::count());
        }
    }

    public function test_fractional_received_quantity_is_rejected_before_stock_and_journal(): void
    {
        $po = $this->order();
        try {
            $this->service->receive($po, [$this->material->id => '1.9'], $this->finance);
            $this->fail('Fractional receipt accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('received_quantities.'.$this->material->id, $exception->errors());
            $this->assertSame('ORDERED', $po->fresh()->status->value);
            $this->assertSame(0, StockLedger::where('ref_type', 'purchase_order')->where('ref_id', $po->id)->count());
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_payment_locks_receipt_journal_before_reading_balance(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->service->payDebt($po, $this->finance, [
            'uuid' => 'f278e941-11d1-4908-8153-591f44c0c453', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'amount_minor' => 1000,
        ]);

        $lockAt = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'journal_entries')
            && str_contains($sql, 'source_type') && str_contains($sql, 'for update'), $queries), true);
        $balanceAt = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'purchase_order_payments')
            && str_contains($sql, 'sum('), $queries), true);
        $this->assertNotFalse($lockAt);
        $this->assertNotFalse($balanceAt);
        $this->assertLessThan($balanceAt, $lockAt);
    }

    public function test_reversed_payment_restores_debt_and_reversed_receipt_removes_it(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        $first = $this->service->payDebt($po, $this->finance, [
            'uuid' => 'f278e941-11d1-4908-8153-591f44c0c453', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'amount_minor' => 10000,
        ]);
        $paymentJournal = $first->journal()->firstOrFail();
        app(JournalService::class)->reverse($paymentJournal, 'Pembayaran salah');
        $this->assertSame(25000, $po->unpaidMinor());

        $second = $this->service->payDebt($po, $this->finance, [
            'uuid' => 'ee92d7f3-e52e-4804-9339-349321098abc', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'amount_minor' => 25000,
        ]);
        $this->assertSame(0, $po->unpaidMinor());
        $this->assertNotSame($first->id, $second->id);

        app(JournalService::class)->reverse($second->journal()->firstOrFail(), 'Batalkan pembayaran pengganti');
        $this->assertSame(25000, $po->unpaidMinor());
        app(JournalService::class)->reverse($po->receiptJournal()->firstOrFail(), 'Penerimaan salah');
        $this->assertSame(0, $po->unpaidMinor());
        $liabilityId = Account::where('code', '2-1400')->firstOrFail()->id;
        $this->assertSame((int) JournalLine::where('account_id', $liabilityId)->sum('debit_minor'),
            (int) JournalLine::where('account_id', $liabilityId)->sum('credit_minor'));
        try {
            $this->service->payDebt($po, $this->finance, [
                'uuid' => '271f377a-315e-47e9-8d55-7012fec43340', 'paid_on' => today()->toDateString(),
                'cash_account_id' => $this->cash->id, 'amount_minor' => 1,
            ]);
            $this->fail('Payment after receipt reversal accepted');
        } catch (ValidationException) {
            $this->assertSame(2, PurchaseOrderPayment::count());
        }
    }

    public function test_pre_cutover_order_has_no_payable_debt_or_journal(): void
    {
        $po = $this->order();
        $po->update(['status' => 'RECEIVED', 'received_at' => '2026-09-01 10:00:00', 'received_by' => $this->finance->id]);
        $po->lines()->firstOrFail()->update(['qty_received' => 4]);

        $this->assertSame(0, $po->unpaidMinor());
        $this->assertSame(0, JournalEntry::count());
        try {
            $this->service->payDebt($po, $this->finance, [
                'uuid' => 'c9e3d871-e878-4a89-a81e-8e59e6e55caf', 'paid_on' => today()->toDateString(),
                'cash_account_id' => $this->cash->id, 'amount_minor' => 1000,
            ]);
            $this->fail('Legacy PO payment accepted');
        } catch (ValidationException) {
            $this->assertSame(0, PurchaseOrderPayment::count());
        }
    }

    public function test_migration_refuses_rollback_that_would_erase_journal_source(): void
    {
        $po = $this->service->receive($this->order(), [], $this->finance);
        $this->service->payDebt($po, $this->finance, [
            'uuid' => 'f278e941-11d1-4908-8153-591f44c0c453', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'amount_minor' => 1000,
        ]);
        $migration = require database_path('migrations/2026_10_18_000001_add_purchase_order_accounting.php');
        try {
            $migration->down();
            $this->fail('Rollback erased payment history');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('must be preserved', $exception->getMessage());
            $this->assertDatabaseCount('purchase_order_payments', 1);
            $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'payment_method' => 'CREDIT']);
        }
    }
}
