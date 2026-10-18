<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\AccountingPeriods;
use App\Filament\Pages\GeneralLedger;
use App\Filament\Pages\OpeningBalances;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Journals\JournalResource;
use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use App\Services\Menu\MenuLabels;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AccountingPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, ['view']);
    }

    public function test_accounting_navigation_is_grouped_and_ordered(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        $items = [
            AccountResource::class => 'Bagan Akun',
            JournalResource::class => 'Jurnal Umum',
            GeneralLedger::class => 'Buku Besar',
            AccountingPeriods::class => 'Periode Akuntansi',
            OpeningBalances::class => 'Saldo Awal',
        ];

        $this->assertContains('Akuntansi', MenuLabels::groups());
        foreach (array_keys($items) as $class) {
            $this->assertSame('Akuntansi', $class::getNavigationGroup());
        }
        $this->assertSame([1, 2, 3, 4, 5], array_map(
            fn (string $class): ?int => $class::getNavigationSort(),
            array_keys($items),
        ));
        $this->get('/admin')->assertSuccessful()->assertSeeInOrder(array_values($items));
    }

    public function test_administrator_can_read_every_accounting_screen_but_cannot_write(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $custom = Account::query()->create([
            'code' => '6-1900', 'name' => 'Beban Uji', 'type' => 'EXPENSE',
            'normal_balance' => 'DEBIT', 'is_active' => true,
        ]);
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $entry = app(JournalService::class)->post([
            'entry_date' => '2026-10-16', 'description' => 'Uji', 'source_type' => 'test',
            'source_id' => 1, 'purpose' => 'RECORD',
        ], [
            ['account_id' => $cash->id, 'debit_minor' => 1000, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => 1000],
        ]);
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 11, 'status' => 'CLOSED']);
        $this->actingAs($admin);
        foreach ([AccountResource::getUrl(), JournalResource::getUrl(),
            JournalResource::getUrl('view', ['record' => $entry]), GeneralLedger::getUrl(),
            AccountingPeriods::getUrl(), OpeningBalances::getUrl()] as $url) {
            $this->get($url)->assertSuccessful();
        }
        $this->get(AccountResource::getUrl('create'))->assertForbidden();
        $this->assertFalse(AccountResource::canCreate());
        Livewire::test(ListAccounts::class)->assertDontSee('Tambah Akun')
            ->assertTableActionHidden('deactivate', $custom);
        Livewire::test(ListJournals::class)->assertTableActionHidden('reverse', $entry);
        // Filament ignores a forged call to an action it did not mount; it returns 200 without writing.
        Livewire::test(ListAccounts::class)
            ->call('mountTableAction', 'deactivate', (string) $custom->id)
            ->call('callMountedTableAction')->assertSuccessful();
        Livewire::test(ListJournals::class)
            ->call('mountTableAction', 'reverse', (string) $entry->id)
            ->setTableActionData(['reason' => 'Koreksi'])
            ->call('callMountedTableAction')->assertSuccessful();
        Livewire::test(AccountingPeriods::class)
            ->assertDontSee('Tutup Periode')->assertDontSee('Buka kembali')
            ->call('setStatus', 2026, 10, 'CLOSED')->assertForbidden();
        Livewire::test(OpeningBalances::class)
            ->assertDontSee('Posting Saldo Awal')->assertDontSee('Tambah Baris')
            ->call('submit')->assertForbidden();
        $this->assertTrue($custom->fresh()->is_active);
        $this->assertNull($entry->fresh()->reversal);
        $this->assertSame('OPEN', AccountingPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail()->status);
        $this->assertSame('CLOSED', AccountingPeriod::query()->where('year', 2026)->where('month', 11)->firstOrFail()->status);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_finance_can_read_chart_journal_and_ledger_but_cannot_manage_them(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->get(AccountResource::getUrl())->assertSuccessful();
        $this->get(JournalResource::getUrl())->assertSuccessful();
        $this->get(GeneralLedger::getUrl())->assertSuccessful();
        $this->get('/admin')->assertSuccessful()
            ->assertSeeInOrder(['Bagan Akun', 'Jurnal Umum', 'Buku Besar'])
            ->assertDontSee('Periode Akuntansi')->assertDontSee('Saldo Awal');
        $this->get(AccountResource::getUrl('create'))->assertForbidden();
        $this->get(AccountingPeriods::getUrl())->assertForbidden();
        $this->get(OpeningBalances::getUrl())->assertForbidden();
        $this->assertFalse(AccountResource::canCreate());
    }

    public function test_ledger_table_renders_selected_account_and_integer_running_balance(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $entry = app(JournalService::class)->postOpening('2026-10-01', [
            ['account_id' => $cash->id, 'debit_minor' => 125000, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => 125000],
        ]);

        Livewire::test(GeneralLedger::class)->set('accountId', $cash->id)
            ->assertSee($entry->number)->assertSee('Rp 125.000');
    }

    public function test_wrong_role_gets_403_on_accounting_pages(): void
    {
        foreach ([Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            PermissionMatrix::set($role, PanelModule::ATTENDANCE, ['view']);
            $this->actingAs(User::factory()->role($role)->create());
            $this->get('/admin')->assertSuccessful()->assertDontSee('Akuntansi');
            $this->assertFalse(AccountResource::canViewAny());
            $this->assertFalse(JournalResource::canViewAny());
            $this->assertFalse(GeneralLedger::canAccess());
            $this->assertFalse(AccountingPeriods::canAccess());
            $this->assertFalse(OpeningBalances::canAccess());
            foreach ([AccountResource::getUrl(), JournalResource::getUrl(), GeneralLedger::getUrl(), AccountingPeriods::getUrl(), OpeningBalances::getUrl()] as $url) {
                $this->get($url)->assertForbidden();
            }
        }
    }

    public function test_management_can_create_account_and_close_reopen_period_with_audit(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $this->get(AccountResource::getUrl('create'))->assertSuccessful();
        $this->get(AccountingPeriods::getUrl())->assertSuccessful();
        $this->get(OpeningBalances::getUrl())->assertSuccessful();
        $this->get('/admin')->assertSuccessful()->assertSeeInOrder([
            'Bagan Akun', 'Jurnal Umum', 'Buku Besar', 'Periode Akuntansi', 'Saldo Awal',
        ]);

        Livewire::test(CreateAccount::class)
            ->fillForm(['code' => '6-1900', 'name' => 'Beban Uji', 'type' => 'EXPENSE'])
            ->call('create')->assertHasNoFormErrors();
        $account = Account::query()->where('code', '6-1900')->firstOrFail();
        $this->assertSame('DEBIT', $account->normal_balance);
        $this->assertFalse($account->is_system);

        Livewire::test(AccountingPeriods::class)->call('setStatus', 2026, 10, 'CLOSED')
            ->call('setStatus', 2026, 10, 'OPEN');
        $this->assertSame(2, AuditLog::query()->where('subject_type', AccountingPeriod::class)->count());
    }

    public function test_system_account_cannot_change_type_or_be_deleted(): void
    {
        $account = Account::query()->where('code', '1-1100')->firstOrFail();
        $this->assertTrue($account->is_system);
        try {
            $account->update(['type' => 'EXPENSE']);
            $this->fail('System account type changed.');
        } catch (ValidationException) {
            $this->assertSame('ASSET', $account->fresh()->type);
        }
        $this->expectException(ValidationException::class);
        $account->delete();
    }

    public function test_ledger_shows_integer_running_balance(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $service = app(JournalService::class);
        foreach ([1000, 500] as $index => $amount) {
            $service->post([
                'entry_date' => '2026-10-16', 'description' => 'Uji', 'source_type' => 'test',
                'source_id' => $index + 1, 'purpose' => 'RECORD',
            ], [
                ['account_id' => $cash->id, 'debit_minor' => $amount, 'credit_minor' => 0],
                ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => $amount],
            ]);
        }
        $service->post([
            'entry_date' => '2026-10-17', 'description' => 'Pengeluaran', 'source_type' => 'test',
            'source_id' => 3, 'purpose' => 'RECORD',
        ], [
            ['account_id' => $equity->id, 'debit_minor' => 2000, 'credit_minor' => 0],
            ['account_id' => $cash->id, 'debit_minor' => 0, 'credit_minor' => 2000],
        ]);
        $page = Livewire::test(GeneralLedger::class)->instance();
        $balances = $page->ledgerRows($cash)->pluck('running_minor')->map(fn ($value): int => (int) $value)->all();
        $this->assertSame([1000, 1500, -500], $balances);
        $this->get(GeneralLedger::getUrl())->assertSuccessful();
    }

    public function test_management_can_submit_opening_reverse_it_and_deactivate_custom_account(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 16));
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        Livewire::test(OpeningBalances::class)
            ->set('entryDate', '2026-10-16')
            ->set('lines', [
                ['account_id' => $cash->id, 'debit_minor' => 1000, 'credit_minor' => 0],
                ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => 1000],
            ])->call('submit')->assertHasNoErrors();
        $entry = JournalEntry::query()->where('purpose', 'OPENING')->firstOrFail();
        Livewire::test(ListJournals::class)->callTableAction('reverse', $entry, ['reason' => 'Koreksi'])
            ->assertHasNoErrors();
        $this->assertNotNull($entry->fresh()->reversal);

        $custom = Account::query()->create([
            'code' => '6-1900', 'name' => 'Beban Uji', 'type' => 'EXPENSE',
            'normal_balance' => 'DEBIT', 'is_active' => true,
        ]);
        Livewire::test(ListAccounts::class)->callTableAction('deactivate', $custom)->assertHasNoErrors();
        $this->assertFalse($custom->fresh()->is_active);
    }

    public function test_journal_filters_date_account_and_source(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $bank = Account::query()->where('code', '1-1300')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $service = app(JournalService::class);
        $first = $service->post([
            'entry_date' => '2026-10-16', 'description' => 'Pertama', 'source_type' => 'cash_test',
            'source_id' => 1, 'purpose' => 'RECORD',
        ], [
            ['account_id' => $cash->id, 'debit_minor' => 1000, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => 1000],
        ]);
        $second = $service->post([
            'entry_date' => '2026-11-16', 'description' => 'Kedua', 'source_type' => 'bank_test',
            'source_id' => 2, 'purpose' => 'RECORD',
        ], [
            ['account_id' => $bank->id, 'debit_minor' => 500, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => 500],
        ]);

        Livewire::test(ListJournals::class)->filterTable('tanggal', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListJournals::class)->filterTable('account', ['account_id' => $bank->id])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        Livewire::test(ListJournals::class)->filterTable('sumber', ['source_type' => 'cash_test'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_corrected_opening_date_and_running_balance_exclude_old_opening_pair(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $lines = fn (int $amount): array => [
            ['account_id' => $cash->id, 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => $amount],
        ];
        $service = app(JournalService::class);
        $old = $service->postOpening('2026-10-15', $lines(1000));
        $reversal = $service->reverse($old, 'Tanggal cutover salah');
        $this->assertSame('2026-10-15', $reversal->entry_date->toDateString());
        $service->postOpening('2026-10-16', $lines(2000));
        $page = Livewire::test(GeneralLedger::class)->instance();
        $balances = $page->ledgerRows($cash)->pluck('running_minor')->map(fn ($value): int => (int) $value)->all();
        $this->assertSame([2000], $balances);
    }

    public function test_same_date_opening_replacement_keeps_running_balance_in_sequence(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $lines = fn (int $amount): array => [
            ['account_id' => $cash->id, 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => $equity->id, 'debit_minor' => 0, 'credit_minor' => $amount],
        ];
        $service = app(JournalService::class);
        $old = $service->postOpening('2026-10-16', $lines(1000));
        $service->reverse($old, 'Nilai salah');
        $service->postOpening('2026-10-16', $lines(2000));
        $page = Livewire::test(GeneralLedger::class)->instance();
        $balances = $page->ledgerRows($cash)->pluck('running_minor')->map(fn ($value): int => (int) $value)->all();
        $this->assertSame([1000, 0, 2000], $balances);
    }
}
