<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exports\MonthlyOperatingExpenseExport;
use App\Filament\Pages\OperatingExpenseMatrix;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\CashTransactions\Pages\ListCashTransactions;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\EmployeeProfile;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\OfficeItem;
use App\Models\User;
use App\Services\CashTransactionService;
use App\Services\JournalService;
use App\Services\Reporting\MonthlyOperatingExpenseService;
use Database\Seeders\DailyOperatingExpenseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DailyOperatingExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        app(DailyOperatingExpenseSeeder::class)->run();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->travelTo(now()->setDate(2026, 10, 24));
    }

    private function input(string $category, int $quantity, ?int $price = null): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
            'cash_category_id' => CashCategory::query()->where('name', $category)->value('id'),
            'cash_account_id' => CashAccount::query()->where('name', 'Bank')->value('id'),
            'quantity' => $quantity, 'unit_price_minor' => $price ?? CashCategory::query()
                ->where('name', $category)->value('default_unit_price_minor'),
            'description' => $category.' hari ini',
        ];
    }

    public function test_ice_amount_and_expense_journal_use_integer_quantity_times_price(): void
    {
        $transaction = app(CashTransactionService::class)->record($this->finance, $this->input('Es batu Rider', 38));
        $this->assertSame(646000, $transaction->amount_minor);
        $this->assertSame(17000, $transaction->unit_price_minor);
        $entry = JournalEntry::query()->where('source_type', 'cash_transaction')->where('source_id', $transaction->id)->firstOrFail();
        $lines = $entry->lines()->with('account')->get()->mapWithKeys(fn ($line) => [
            $line->account->code => [(int) $line->debit_minor, (int) $line->credit_minor],
        ])->all();
        $this->assertSame(['6-2400' => [646000, 0], '1-1300' => [0, 646000]], $lines);
    }

    public function test_price_override_is_a_transaction_snapshot_and_mismatched_amount_is_rejected(): void
    {
        $category = CashCategory::query()->where('name', 'Es batu Rider')->firstOrFail();
        $input = $this->input('Es batu Rider', 38, 18000);
        $transaction = app(CashTransactionService::class)->record($this->finance, $input);
        $this->assertSame(684000, $transaction->amount_minor);
        $this->assertSame(18000, $transaction->unit_price_minor);
        $this->assertSame(17000, $category->fresh()->default_unit_price_minor);
        try {
            app(CashTransactionService::class)->record($this->finance, [...$this->input('Es batu Rider', 38), 'amount_minor' => 1]);
            $this->fail('A mismatched amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_minor', $exception->errors());
        }
    }

    public function test_runner_and_location_are_saved_and_monthly_matrix_filters_employee(): void
    {
        $runner = User::factory()->role(Role::RIDER)->create();
        EmployeeProfile::query()->create(['user_id' => $runner->id]);
        $other = User::factory()->role(Role::RIDER)->create();
        $location = Location::query()->create(['name' => 'Blok M', 'lat' => -6.24, 'lng' => 106.8, 'geofence_m' => 100]);
        $service = app(CashTransactionService::class);
        $transaction = $service->record($this->finance, [...$this->input('Bensin Runner', 1),
            'employee_id' => $runner->id, 'location_id' => $location->id]);
        $service->record($this->finance, [...$this->input('Bensin Runner', 1), 'employee_id' => $other->id]);
        $matrix = app(MonthlyOperatingExpenseService::class)->matrix('2026-10', $runner->id);
        $this->assertSame($runner->id, $transaction->employee_id);
        $this->assertSame($location->id, $transaction->location_id);
        $this->assertSame(20000, $matrix['totals']['amount_minor']);
        $this->assertSame(1, $matrix['totals']['quantity']);
        $this->actingAs($this->finance)->get(OperatingExpenseMatrix::getUrl())->assertSuccessful();
        Livewire::test(OperatingExpenseMatrix::class)->set('employeeId', $runner->id)
            ->call('exportExcel')->assertFileDownloaded('biaya-harian-2026-10.xlsx');
    }

    public function test_copy_yesterday_is_unsaved_until_confirmed_and_replay_is_idempotent(): void
    {
        $service = app(CashTransactionService::class);
        $yesterday = $this->input('Es batu Rider', 38);
        $yesterday['date'] = today()->subDay()->toDateString();
        $service->record($this->finance, $yesterday);
        $service->record($this->finance, [...$this->input('Listrik', 1), 'unit_price_minor' => null,
            'quantity' => null, 'amount_minor' => 10000, 'date' => $yesterday['date']]);
        $draft = $service->draftFromYesterday($this->finance);
        $this->assertCount(1, $draft);
        $this->assertSame(38, $draft[0]['quantity']);
        $this->assertSame(0, CashTransaction::query()->whereDate('date', today())->count());
        $this->actingAs($this->finance);
        $mounted = Livewire::test(ListCashTransactions::class)->mountAction('copyYesterday');
        $mountedRows = array_values($mounted->get('mountedActions')[0]['data']['rows']);
        $this->assertSame(38, $mountedRows[0]['quantity']);
        $this->assertSame(0, CashTransaction::query()->whereDate('date', today())->count());
        $draft[0]['quantity'] = 39;
        $key = (string) Str::uuid();
        $posted = $service->confirmDailyDraft($this->finance, $key, $draft);
        $this->assertSame(663000, $posted[0]->amount_minor);
        $this->assertSame($posted[0]->id, $service->confirmDailyDraft($this->finance, $key, $draft)[0]->id);
        $this->assertSame(1, CashTransaction::query()->whereDate('date', today())->count());
        $this->assertSame(1, DB::table('cash_daily_batches')->count());
        try {
            $service->confirmDailyDraft($this->finance, $key, [[...$draft[0], 'quantity' => 40]]);
            $this->fail('Reused batch key with new content was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }
        Livewire::test(ListCashTransactions::class)->callAction('copyYesterday', [
            'request_key' => (string) Str::uuid(), 'rows' => $draft,
        ])->assertHasNoErrors();
        $this->assertSame(2, CashTransaction::query()->whereDate('date', today())->count());
    }

    public function test_newly_daily_category_does_not_copy_unpriced_legacy_quantity(): void
    {
        $category = CashCategory::query()->where('name', 'Keperluan Kantor')->firstOrFail();
        $item = OfficeItem::query()->create(['name' => 'Kertas uji', 'unit' => 'rim']);
        app(CashTransactionService::class)->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'date' => today()->subDay()->toDateString(),
            'cash_category_id' => $category->id, 'cash_account_id' => CashAccount::query()->where('name', 'Bank')->value('id'),
            'office_item_id' => $item->id, 'quantity' => 2, 'amount_minor' => 10000, 'description' => 'Kertas lama',
        ]);
        $category->update(['is_daily' => true]);
        $this->assertSame([], app(CashTransactionService::class)->draftFromYesterday($this->finance));
    }

    public function test_reversed_cash_journal_is_not_copied_even_if_cash_row_still_says_posted(): void
    {
        $service = app(CashTransactionService::class);
        $input = $this->input('Es batu Rider', 2);
        $input['date'] = today()->subDay()->toDateString();
        $transaction = $service->record($this->finance, $input);
        $entry = JournalEntry::query()->where('source_type', 'cash_transaction')->where('source_id', $transaction->id)->firstOrFail();
        $this->actingAs($this->finance);
        app(JournalService::class)->reverse($entry, 'Koreksi jurnal');
        $this->assertSame('POSTED', $transaction->fresh()->status);
        $this->assertSame([], $service->draftFromYesterday($this->finance));
    }

    public function test_non_daily_category_default_price_autofills_cash_form(): void
    {
        $category = CashCategory::query()->create([
            'name' => 'Biaya uji per pack', 'direction' => 'OUT',
            'account_id' => Account::query()->where('code', '6-1800')->value('id'),
            'default_unit_price_minor' => 17000, 'unit' => 'pack', 'is_daily' => false, 'is_active' => true,
        ]);
        $this->actingAs($this->finance);
        Livewire::test(CreateCashTransaction::class)
            ->set('data.cash_category_id', $category->id)
            ->assertSet('data.unit_price_minor', 17000)
            ->set('data.quantity', 38)
            ->assertSet('data.amount_minor', 646000)
            ->set('data.cash_account_id', CashAccount::query()->where('name', 'Bank')->value('id'))
            ->set('data.description', 'Biaya uji')
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_transactions', [
            'cash_category_id' => $category->id, 'quantity' => 38,
            'unit_price_minor' => 17000, 'amount_minor' => 646000, 'is_daily_expense' => false,
        ]);
    }

    public function test_priced_income_category_shows_quantity_and_computes_amount(): void
    {
        $category = CashCategory::query()->where('name', 'Pendapatan Lain-lain')->firstOrFail();
        $category->update(['default_unit_price_minor' => 5000, 'unit' => 'item']);
        $this->actingAs($this->finance);
        Livewire::test(CreateCashTransaction::class)
            ->set('data.cash_category_id', $category->id)
            ->assertSet('data.unit_price_minor', 5000)
            ->set('data.quantity', 2)
            ->assertSet('data.amount_minor', 10000)
            ->set('data.cash_account_id', CashAccount::query()->where('name', 'Bank')->value('id'))
            ->set('data.description', 'Penjualan item uji')
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_transactions', ['cash_category_id' => $category->id, 'quantity' => 2,
            'unit_price_minor' => 5000, 'amount_minor' => 10000]);
    }

    public function test_daily_category_rejects_income_or_asset_account(): void
    {
        foreach (['Pendapatan Lain-lain', 'Petty Cash (isi ulang)'] as $name) {
            try {
                CashCategory::query()->where('name', $name)->firstOrFail()->update(['is_daily' => true]);
                $this->fail('Non-expense daily category was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_daily', $exception->errors());
            }
        }
    }

    public function test_cross_month_void_is_reported_in_reversal_month_and_non_daily_rows_stay_out(): void
    {
        $service = app(CashTransactionService::class);
        $daily = $service->record($this->finance, $this->input('Es batu Rider', 38));
        $nondaily = CashCategory::query()->where('name', 'Air/Galon')->firstOrFail();
        $nondaily->update(['default_unit_price_minor' => 7000]);
        $service->record($this->finance, [
            ...$this->input('Air isi ulang', 1), 'uuid' => (string) Str::uuid(),
            'cash_category_id' => $nondaily->id, 'quantity' => 1, 'unit_price_minor' => 7000,
        ]);
        $this->travelTo(now()->setDate(2026, 11, 2));
        $service->void($this->finance, $daily, 'Salah input');
        $report = app(MonthlyOperatingExpenseService::class);
        $october = $report->matrix('2026-10');
        $november = $report->matrix('2026-11');
        $this->assertSame(653000, $october['totals']['amount_minor']);
        $this->assertContains('Biaya lain / penyesuaian akun terkait', array_column($october['rows'], 'category'));
        $this->assertNotContains('Air/Galon', array_column($october['rows'], 'category'));
        $this->assertSame(-646000, $november['totals']['amount_minor']);
        $this->assertSame(-38, $november['totals']['quantity']);
        $expenseAccounts = Account::query()->whereIn('code', ['6-2400', '6-1500'])->pluck('id');
        foreach (['2026-10' => $october, '2026-11' => $november] as $month => $matrix) {
            $lastDay = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
            $net = (int) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
                ->whereIn('l.account_id', $expenseAccounts)
                ->whereBetween('j.entry_date', [$month.'-01', $lastDay])
                ->selectRaw('SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS total')->value('total');
            $this->assertSame($net, $matrix['totals']['amount_minor']);
        }
    }

    public function test_october_matrix_matches_expense_ledger_and_excel_numbers(): void
    {
        $service = app(CashTransactionService::class);
        $service->record($this->finance, $this->input('Es batu Rider', 38));
        $service->record($this->finance, $this->input('Air isi ulang', 2));
        $this->travelTo(now()->addDay());
        $service->record($this->finance, $this->input('Es batu Rider', 1));
        $matrix = app(MonthlyOperatingExpenseService::class)->matrix('2026-10');
        $this->assertSame(677000, $matrix['totals']['amount_minor']);
        $accountIds = Account::query()->whereIn('code', ['6-2400', '6-1500'])->pluck('id');
        $ledger = (int) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->whereIn('l.account_id', $accountIds)->whereBetween('j.entry_date', ['2026-10-01', '2026-10-31'])
            ->selectRaw('SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS total')->value('total');
        $this->assertSame($ledger, $matrix['totals']['amount_minor']);
        $export = new MonthlyOperatingExpenseExport($matrix);
        $this->assertSame($matrix['totals']['amount_minor'], $export->collection()->last()[count($export->headings()) - 1]);
    }

    public function test_seeder_twice_keeps_existing_category_edits_and_no_duplicates(): void
    {
        $category = CashCategory::query()->where('name', 'Grab')->firstOrFail();
        $category->update(['default_unit_price_minor' => 23000]);
        app(DailyOperatingExpenseSeeder::class)->run();
        app(DailyOperatingExpenseSeeder::class)->run();
        $this->assertSame(6, CashCategory::query()->where('is_daily', true)->count());
        $this->assertSame(23000, $category->fresh()->default_unit_price_minor);
        $this->assertSame(1, Account::query()->where('code', '6-2400')->count());
    }

    public function test_wrong_role_gets_403_on_daily_post_and_matrix(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();
        $this->expectException(HttpException::class);
        app(CashTransactionService::class)->confirmDailyDraft($staff, (string) Str::uuid(), [$this->input('Es batu Rider', 1)]);
    }

    public function test_wrong_role_cannot_open_matrix_page(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();
        $this->actingAs($staff)->get(OperatingExpenseMatrix::getUrl())->assertForbidden();
        $this->actingAs($staff)->get(CreateCashTransaction::getUrl())->assertForbidden();
    }
}
