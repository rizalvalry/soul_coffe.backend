<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Exports\RevenueExport;
use App\Filament\Pages\AccountingPeriods;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CentralKitchen;
use App\Models\DirectSale;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\CashTransactionService;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MoneyGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $manager;

    private User $rider;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->cart = Cart::create(['code' => '0055', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
    }

    private function account(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function postJournal(string $date, string $source, int $sourceId, array $lines, string $purpose = 'RECORD'): void
    {
        app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Jurnal uji '.$sourceId,
            'source_type' => $source, 'source_id' => $sourceId, 'purpose' => $purpose,
            'posted_by' => $this->finance->id,
        ], array_map(fn (array $line): array => [
            'account_id' => $this->account($line[0]), 'debit_minor' => $line[1], 'credit_minor' => $line[2],
        ], $lines));
    }

    private function settlement(string $date, string $status, int $gross): Settlement
    {
        return Settlement::query()->create([
            'operating_date' => $date, 'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'status' => $status, 'cash_minor' => $gross, 'declared_total_minor' => $gross,
            'expected_total_minor' => $gross,
        ]);
    }

    public function test_cash_bank_seed_is_idempotent_and_transfer_reverses(): void
    {
        $migration = require database_path('migrations/2026_10_21_000055_seed_cash_bank_transfer.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, CashAccount::query()->where('name', 'Kas Setoran')->count());
        $this->assertSame(1, CashCategory::query()->where('name', 'Setor tunai ke bank')->count());
        $this->assertSame('1-1200', CashAccount::query()->where('name', 'Kas Setoran')->firstOrFail()->account->code);
        $this->assertSame('1-1300', CashCategory::query()->where('name', 'Setor tunai ke bank')->firstOrFail()->account->code);

        $date = today()->toDateString();
        $settlement = $this->settlement($date, 'RECONCILED', 1_200_000);
        $this->postJournal($date, 'settlement', $settlement->id, [
            ['1-1200', 1_200_000, 0], ['4-1100', 0, 1_200_000],
        ], 'SETTLEMENT');
        $cash = CashAccount::query()->where('name', 'Kas Setoran')->firstOrFail();
        $bank = CashAccount::query()->where('name', 'Bank')->firstOrFail();
        $this->assertSame(1_200_000, $cash->balanceMinor());
        $input = [
            'uuid' => (string) Str::uuid(), 'date' => $date,
            'cash_category_id' => CashCategory::query()->where('name', 'Setor tunai ke bank')->value('id'),
            'cash_account_id' => $cash->id, 'amount_minor' => 1_200_000, 'description' => 'Setor ke bank',
        ];
        $service = app(CashTransactionService::class);
        $transaction = $service->record($this->finance, $input);
        $this->assertSame($transaction->id, $service->record($this->finance, $input)->id);
        $this->assertSame(0, $cash->balanceMinor());
        $this->assertSame(1_200_000, $bank->balanceMinor());
        $this->assertDatabaseHas('journal_lines', ['account_id' => $bank->account_id,
            'debit_minor' => 1_200_000, 'credit_minor' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $cash->account_id,
            'debit_minor' => 0, 'credit_minor' => 1_200_000]);
        $service->void($this->finance, $transaction, 'Setoran bank dibatalkan');
        $this->assertSame(1_200_000, $cash->balanceMinor());
        $this->assertSame(0, $bank->balanceMinor());
    }

    public function test_bank_deposit_above_balance_is_saved_and_audited_without_blocking(): void
    {
        $input = [
            'uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
            'cash_category_id' => CashCategory::query()->where('name', 'Setor tunai ke bank')->value('id'),
            'cash_account_id' => CashAccount::query()->where('name', 'Kas Setoran')->value('id'),
            'amount_minor' => 1_200_000, 'description' => 'Setor tunai',
        ];
        try {
            app(CashTransactionService::class)->record($this->finance, [
                ...$input, 'cash_account_id' => CashAccount::query()->where('name', 'Kas Kecil Kantor')->value('id'),
            ]);
            $this->fail('Bank deposit used the wrong source account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_account_id', $exception->errors());
            $this->assertDatabaseCount('cash_transactions', 0);
        }
        $category = CashCategory::query()->where('name', 'Setor tunai ke bank')->firstOrFail();
        $category->update(['account_id' => $this->account('1-1100')]);
        try {
            app(CashTransactionService::class)->record($this->finance, $input);
            $this->fail('Bank deposit used the wrong destination account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_category_id', $exception->errors());
            $this->assertDatabaseCount('cash_transactions', 0);
        }
        $category->update(['account_id' => $this->account('1-1300')]);
        $transaction = app(CashTransactionService::class)->record($this->finance, $input);
        $flag = AuditLog::query()->where('action', 'cash_transaction.balance_exceeded')
            ->where('subject_id', $transaction->id)->firstOrFail();
        $this->assertSame(1_200_000, $flag->after_json['excess_minor']);
        $this->assertSame('POSTED', $transaction->status);
        try {
            app(CashTransactionService::class)->record($this->rider, [...$input, 'uuid' => (string) Str::uuid()]);
            $this->fail('Rider was allowed to record a bank deposit.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_period_close_blocks_unapproved_settlement_then_allows_approved_status(): void
    {
        $settlement = $this->settlement('2026-02-10', 'SUBMITTED', 500_000);
        $service = app(AccountingPeriodService::class);
        $this->assertSame(1, $service->closeReadiness(2026, 2)[0]['count']);
        try {
            $service->setStatus(2026, 2, 'CLOSED', $this->manager);
            $this->fail('Unapproved settlement did not block closing.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1 setoran', $exception->getMessage());
        }
        $settlement->update(['status' => 'RECONCILED']);
        $this->assertSame('CLOSED', $service->setStatus(2026, 2, 'CLOSED', $this->manager)->status);
    }

    public function test_period_close_blocks_rider_closing_without_settlement(): void
    {
        RiderClosing::query()->create([
            'operating_date' => '2026-02-10', 'cart_id' => $this->cart->id,
            'rider_id' => $this->rider->id, 'total_minor' => 100_000, 'cogs_minor' => 0,
            'cash_minor' => 100_000, 'qris_minor' => 0, 'transfer_minor' => 0,
            'closed_at' => now(),
        ]);
        $service = app(AccountingPeriodService::class);
        $this->assertSame(1, $service->closeReadiness(2026, 2)[1]['count']);
        $this->expectException(ValidationException::class);
        $service->setStatus(2026, 2, 'CLOSED', $this->manager);
    }

    public function test_repeat_close_rechecks_pending_settlements_without_duplicate_audit(): void
    {
        $service = app(AccountingPeriodService::class);
        $period = $service->setStatus(2026, 3, 'CLOSED', $this->manager);
        $this->settlement('2026-03-10', 'SUBMITTED', 50_000);
        try {
            $service->setStatus(2026, 3, 'CLOSED', $this->manager);
            $this->fail('Repeat close ignored the new pending settlement.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1 setoran', $exception->getMessage());
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'accounting_period.closed')
            ->where('subject_id', $period->id)->count());
    }

    public function test_warning_only_period_closes_and_preserves_warning_in_audit(): void
    {
        $this->postJournal('2026-02-10', 'test_cash', 1, [
            ['1-1200', 20_000, 0], ['3-1200', 0, 20_000],
        ]);
        $service = app(AccountingPeriodService::class);
        $items = collect($service->closeReadiness(2026, 2))->keyBy('code');
        $this->assertSame('warning', $items['cash_settlement_balance']['status']);
        $this->assertSame(20_000, $items['cash_settlement_balance']['count']);
        $period = $service->setStatus(2026, 2, 'CLOSED', $this->manager);
        $audit = AuditLog::query()->where('action', 'accounting_period.closed')
            ->where('subject_id', $period->id)->firstOrFail();
        $this->assertContains('cash_settlement_balance', array_column($audit->after_json['warnings'], 'code'));
    }

    public function test_readiness_lists_clearing_stock_and_depreciation_warnings_on_selected_month(): void
    {
        $date = '2026-02-10';
        $settlement = $this->settlement($date, 'RECONCILED', 11_000);
        $this->postJournal($date, 'settlement', $settlement->id, [
            ['1-1400', 11_000, 0], ['4-1100', 0, 10_000], ['2-1500', 0, 1_000],
        ], 'SETTLEMENT');
        $product = Product::query()->create([
            'code' => 'P055', 'name' => 'Kopi Uji', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1,
        ]);
        DB::table('stock_ledger')->insert([
            'location_type' => 'kitchen', 'location_id' => $this->cart->kitchen_id,
            'product_id' => $product->id, 'movement_type' => MovementType::PRODUCTION_IN->value,
            'qty_delta' => 1, 'cost_minor' => 0, 'cost_pending' => true,
            'actor_id' => $this->rider->id, 'kitchen_id' => $this->cart->kitchen_id,
            'created_at' => '2026-02-10 12:00:00',
        ]);
        FixedAsset::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Mesin Uji',
            'category_id' => DB::table('fixed_asset_categories')->where('name', 'Mesin kopi')->value('id'),
            'acquired_on' => '2026-01-10', 'cost_minor' => 120_000, 'useful_life_months' => 12,
            'residual_minor' => 0, 'status' => 'ACTIVE', 'acquisition_mode' => 'OPENING',
            'created_by' => $this->manager->id,
        ]);
        $items = collect(app(AccountingPeriodService::class)->closeReadiness(2026, 2))->keyBy('code');
        foreach (['clearing_outstanding', 'stock_cost_pending', 'depreciation_unposted'] as $code) {
            $this->assertSame('warning', $items[$code]['status']);
            $this->assertSame(1, $items[$code]['count']);
        }
        $this->actingAs($this->manager);
        Livewire::test(AccountingPeriods::class)->set('year', 2026)->set('month', 2)
            ->assertSee('1 hari penjualan')->assertSee('1 biaya stok')->assertSee('1 aset belum');
    }

    public function test_seed_fails_on_inactive_preexisting_master_row(): void
    {
        $migration = require database_path('migrations/2026_10_21_000055_seed_cash_bank_transfer.php');
        CashAccount::query()->where('name', 'Kas Setoran')->firstOrFail()->update(['is_active' => false]);
        try {
            $migration->up();
            $this->fail('Inactive cash account was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tidak aktif', $exception->getMessage());
        }
        CashAccount::query()->where('name', 'Kas Setoran')->firstOrFail()->update(['is_active' => true]);
        $category = CashCategory::query()->where('name', 'Setor tunai ke bank')->firstOrFail();
        $category->update(['is_active' => false]);
        try {
            $migration->up();
            $this->fail('Inactive bank category was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tidak aktif', $exception->getMessage());
        }
        $category->update(['is_active' => true]);
        CashCategory::query()->create(['name' => 'Setor tunai ke bank', 'direction' => 'OUT',
            'account_id' => $this->account('1-1300'), 'is_active' => true]);
        $this->expectException(RuntimeException::class);
        $migration->up();
    }

    public function test_reversed_depreciation_is_warned_as_unposted(): void
    {
        $asset = FixedAsset::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Mesin Uji',
            'category_id' => DB::table('fixed_asset_categories')->where('name', 'Mesin kopi')->value('id'),
            'acquired_on' => '2026-01-10', 'cost_minor' => 120_000, 'useful_life_months' => 12,
            'residual_minor' => 0, 'status' => 'ACTIVE', 'acquisition_mode' => 'OPENING',
            'created_by' => $this->manager->id,
        ]);
        $this->postJournal('2026-02-28', 'fixed_asset_depreciation', $asset->id, [
            ['6-2000', 10_000, 0], ['1-1900', 0, 10_000],
        ], 'DEPRECIATION_202602');
        $service = app(AccountingPeriodService::class);
        $this->assertSame('ok', collect($service->closeReadiness(2026, 2))
            ->keyBy('code')['depreciation_unposted']['status']);
        $this->travelTo(Carbon::parse('2026-02-28 13:00:00'));
        app(JournalService::class)->reverse(JournalEntry::query()->where('source_type', 'fixed_asset_depreciation')
            ->where('source_id', $asset->id)->firstOrFail(), 'Salah posting');
        $this->travelBack();
        $this->assertSame('warning', collect($service->closeReadiness(2026, 2))
            ->keyBy('code')['depreciation_unposted']['status']);
    }

    public function test_revenue_export_total_matches_revenue_ledger_with_pending_and_corrections(): void
    {
        $date = '2026-02-10';
        $settlement = $this->settlement($date, 'RECONCILED', 110_000);
        $this->postJournal($date, 'settlement', $settlement->id, [
            ['1-1200', 110_000, 0], ['4-1100', 0, 100_000], ['2-1500', 0, 10_000],
        ], 'SETTLEMENT');
        $direct = DirectSale::query()->create([
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'recorded_by' => $this->finance->id, 'occurred_at' => $date.' 12:00:00',
            'total_qty' => 1, 'total_amount_minor' => 55_000, 'payment_method' => 'cash',
        ]);
        $this->postJournal($date, 'direct_sale', $direct->id, [
            ['1-1100', 55_000, 0], ['4-1200', 0, 50_000], ['2-1500', 0, 5_000],
        ], 'DIRECT_SALE');
        $this->postJournal($date, 'rider_closing_correction', 1, [
            ['1-1200', 2_200, 0], ['4-1100', 0, 2_000], ['2-1500', 0, 200],
        ]);
        $this->postJournal($date, 'manual', 1, [
            ['1-1100', 3_000, 0], ['4-1200', 0, 3_000],
        ], 'MANUAL');
        $this->settlement('2026-02-11', 'SUBMITTED', 70_000);
        $this->settlement('2026-02-12', 'RECONCILED', 0);

        $sheets = (new RevenueExport(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28')))->sheets();
        $detail = $sheets[0]->array();
        $pending = $sheets[1]->array();
        $ledger = (int) DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->whereBetween('entry.entry_date', ['2026-02-01', '2026-02-28'])
            ->whereIn('line.account_id', [$this->account('4-1100'), $this->account('4-1200')])
            ->selectRaw('SUM(CAST(line.credit_minor AS SIGNED) - CAST(line.debit_minor AS SIGNED)) AS balance')
            ->value('balance');
        $this->assertSame($ledger, end($detail)[6]);
        $this->assertSame(155_000, $detail[1][6] + $detail[2][6] + $detail[3][6] + $detail[4][6]);
        $this->assertSame(['Setoran', 'Penjualan langsung', 'Koreksi lain', 'Koreksi lain'],
            array_column(array_slice($detail, 1, 4), 1));
        $this->assertSame(2_200, $detail[3][4]);
        $this->assertSame(200, $detail[3][5]);
        $this->assertSame(0, $detail[5][6]);
        $this->assertCount(2, $pending);
        $this->assertSame(70_000, $pending[1][4]);
    }
}
