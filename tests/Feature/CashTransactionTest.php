<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\CashTransactionsExport;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Filament\Resources\CashAccounts\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccounts\Pages\EditCashAccount;
use App\Filament\Resources\CashAccounts\Pages\ListCashAccounts;
use App\Filament\Resources\CashCategories\CashCategoryResource;
use App\Filament\Resources\CashCategories\Pages\CreateCashCategory;
use App\Filament\Resources\CashCategories\Pages\EditCashCategory;
use App\Filament\Resources\CashCategories\Pages\ListCashCategories;
use App\Filament\Resources\CashTransactions\CashTransactionResource;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\CashTransactions\Pages\ListCashTransactions;
use App\Filament\Resources\OfficeItems\OfficeItemResource;
use App\Filament\Resources\OfficeItems\Pages\CreateOfficeItem;
use App\Filament\Resources\OfficeItems\Pages\EditOfficeItem;
use App\Filament\Resources\OfficeItems\Pages\ListOfficeItems;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\OfficeItem;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\CashTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;

class CashTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, ['view']);
    }

    private function input(string $category = 'Listrik', string $cash = 'Bank'): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'date' => today()->toDateString(),
            'cash_category_id' => CashCategory::query()->where('name', $category)->value('id'),
            'cash_account_id' => CashAccount::query()->where('name', $cash)->value('id'),
            'amount_minor' => 125000,
            'description' => 'Biaya kantor uji',
        ];
    }

    private function lines(CashTransaction $transaction): array
    {
        return JournalEntry::query()->where('source_type', 'cash_transaction')
            ->where('source_id', $transaction->id)->where('purpose', 'CASH_TX')
            ->firstOrFail()->lines()->with('account')->get()
            ->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all();
    }

    public function test_out_and_in_post_balanced_journals_and_uuid_replay_is_idempotent(): void
    {
        $service = app(CashTransactionService::class);
        $out = $this->input();
        $transaction = $service->record($this->finance, $out);
        $this->assertSame(['6-1400' => [125000, 0], '1-1300' => [0, 125000]], $this->lines($transaction));
        $this->assertSame($transaction->id, $service->record($this->finance, $out)->id);
        $this->assertSame(1, CashTransaction::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('purpose', 'CASH_TX')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'cash_transaction.created')
            ->where('subject_id', $transaction->id)->count());

        $in = $this->input('Pendapatan Lain-lain');
        $income = $service->record($this->finance, $in);
        $this->assertSame(['1-1300' => [125000, 0], '4-1300' => [0, 125000]], $this->lines($income));
        $this->assertSame(0, CashAccount::query()->where('name', 'Bank')->firstOrFail()->balanceMinor());
    }

    public function test_petty_cash_refill_transfers_bank_to_office_cash_and_balances_match_ledger(): void
    {
        $transaction = app(CashTransactionService::class)->record($this->finance, $this->input('Petty Cash (isi ulang)'));
        $this->assertSame(['1-1100' => [125000, 0], '1-1300' => [0, 125000]], $this->lines($transaction));
        $petty = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        $bank = CashAccount::query()->where('name', 'Bank')->firstOrFail();
        $this->assertSame(125000, $petty->balanceMinor());
        $this->assertSame(-125000, $bank->balanceMinor());
        $ledger = (int) DB::table('journal_lines')->where('account_id', $petty->account_id)
            ->selectRaw('SUM(CAST(debit_minor AS SIGNED) - CAST(credit_minor AS SIGNED)) AS balance')->value('balance');
        $this->assertSame($ledger, $petty->balanceMinor());
    }

    public function test_other_income_uses_a_revenue_account_separate_from_direct_sales(): void
    {
        $other = CashCategory::query()->where('name', 'Pendapatan Lain-lain')->firstOrFail();
        $this->assertSame('4-1300', $other->account->code);
        $this->assertNotSame(Account::query()->where('code', '4-1200')->value('id'), $other->account_id);
    }

    public function test_cash_master_records_reject_incompatible_ledger_accounts(): void
    {
        foreach ([
            fn () => CashCategory::query()->create([
                'name' => 'Salah Arah', 'direction' => 'IN',
                'account_id' => Account::query()->where('code', '6-1400')->value('id'),
            ]),
            fn () => CashAccount::query()->create([
                'name' => 'Kas Salah', 'account_id' => Account::query()->where('code', '4-1300')->value('id'),
            ]),
        ] as $create) {
            try {
                $create();
                $this->fail('Incompatible account mapping was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('account_id', $exception->errors());
            }
        }
    }

    public function test_posted_cash_mappings_and_destination_balance_cannot_be_removed_or_remapped(): void
    {
        app(CashTransactionService::class)->record($this->finance, $this->input('Petty Cash (isi ulang)'));
        $petty = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        $this->assertSame(0, $petty->transactions()->count());
        $this->assertSame(125000, $petty->balanceMinor());
        foreach ([
            fn () => $petty->delete(),
            fn () => $petty->update(['account_id' => Account::query()->where('code', '1-1400')->value('id')]),
            fn () => CashCategory::query()->where('name', 'Petty Cash (isi ulang)')->firstOrFail()->update(['name' => 'Transfer lain']),
        ] as $change) {
            try {
                $change();
                $this->fail('Posted cash mapping changed.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(125000, $petty->fresh()->balanceMinor());
        $this->assertDatabaseHas('cash_categories', ['name' => 'Petty Cash (isi ulang)']);
    }

    public function test_cash_mapping_edits_lock_the_row_before_the_update(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $category = CashCategory::query()->where('name', 'Listrik')->firstOrFail();
        $category->update(['name' => 'Listrik Uji']);
        $cash = CashAccount::query()->where('name', 'Bank')->firstOrFail();
        $cash->update(['name' => 'Bank Uji']);

        foreach (['cash_categories', 'cash_accounts'] as $table) {
            $lock = array_search(true, array_map(
                fn (string $sql): bool => str_contains($sql, $table) && str_contains(strtolower($sql), 'for update'),
                $queries,
            ), true);
            $update = array_search(true, array_map(
                fn (string $sql): bool => str_starts_with(strtolower($sql), "update `$table`"),
                $queries,
            ), true);
            $this->assertNotFalse($lock);
            $this->assertNotFalse($update);
            $this->assertLessThan($update, $lock);
        }
    }

    public function test_office_items_are_only_allowed_on_cash_out(): void
    {
        $item = OfficeItem::query()->create(['name' => 'Kertas', 'unit' => 'rim']);
        $this->expectException(ValidationException::class);
        app(CashTransactionService::class)->record($this->finance, [
            ...$this->input('Pendapatan Lain-lain'), 'office_item_id' => $item->id, 'quantity' => 1,
        ]);
    }

    public function test_used_office_item_cannot_be_deleted(): void
    {
        $item = OfficeItem::query()->create(['name' => 'Kertas', 'unit' => 'rim']);
        app(CashTransactionService::class)->record($this->finance, [
            ...$this->input(), 'office_item_id' => $item->id, 'quantity' => 2,
        ]);

        $this->expectException(ValidationException::class);
        $item->delete();
    }

    public function test_rollback_refuses_edited_cash_seed_data(): void
    {
        DB::table('cash_categories')->where('name', 'Listrik')->update(['is_active' => false]);
        $migration = require database_path('migrations/2026_10_16_000200_create_cash_management_tables.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_void_keeps_transaction_and_posts_reversal_once(): void
    {
        $service = app(CashTransactionService::class);
        $transaction = $service->record($this->finance, $this->input());
        $service->void($this->finance, $transaction, 'Salah catat');
        $this->assertSame('VOID', $transaction->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash_transaction.voided')
            ->where('subject_id', $transaction->id)->count());
        $this->assertSame(1, CashTransaction::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('purpose', 'REVERSAL')->count());
        $this->assertSame(0, CashAccount::query()->where('name', 'Bank')->firstOrFail()->balanceMinor());
        $this->expectException(ValidationException::class);
        $service->void($this->finance, $transaction, 'Lagi');
    }

    public function test_invalid_amount_item_pair_and_reused_uuid_with_different_data_are_rejected(): void
    {
        $service = app(CashTransactionService::class);
        $data = $this->input();
        $service->record($this->finance, $data);
        try {
            $service->record($this->finance, [...$data, 'amount_minor' => 1]);
            $this->fail('Different UUID payload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('uuid', $exception->errors());
        }
        $item = OfficeItem::query()->create(['name' => 'Kertas', 'unit' => 'rim']);
        try {
            $service->record($this->finance, [...$this->input(), 'office_item_id' => $item->id]);
            $this->fail('Item without quantity was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $service->record($this->finance, [...$this->input(), 'amount_minor' => 0]);
    }

    public function test_receipt_is_private_and_only_finance_and_management_can_download_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('cash-evidence/bukti.pdf', '%PDF-1.4 test');
        $transaction = app(CashTransactionService::class)->record($this->finance,
            [...$this->input(), 'evidence_path' => 'cash-evidence/bukti.pdf']);
        $url = route('admin.cash-receipts.download', $transaction);
        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->role(Role::STAFF)->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create())->get($url)->assertSuccessful();
        $this->actingAs($this->finance)->get($url)->assertSuccessful();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($management)->get($url)->assertSuccessful();
        $management->update(['is_active' => false]);
        $this->actingAs($management)->get($url)->assertForbidden();
        $this->assertFalse(Storage::disk('public')->exists($transaction->evidence_path));
    }

    public function test_receipt_path_cannot_escape_its_private_directory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('other-private.pdf', '%PDF-1.4 private');
        $path = 'cash-evidence/../other-private.pdf';
        try {
            app(CashTransactionService::class)->record($this->finance, [...$this->input(), 'evidence_path' => $path]);
            $this->fail('Escaping receipt path was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_path', $exception->errors());
        }
        $transaction = app(CashTransactionService::class)->record($this->finance, $this->input());
        DB::table('cash_transactions')->where('id', $transaction->id)->update(['evidence_path' => $path]);
        $this->actingAs($this->finance)->get(route('admin.cash-receipts.download', $transaction))->assertNotFound();
    }

    public function test_finance_roles_can_open_cash_cms_resources(): void
    {
        $resources = [CashTransactionResource::class, CashCategoryResource::class,
            CashAccountResource::class, OfficeItemResource::class];
        foreach ($resources as $resource) {
            foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
                $this->actingAs(User::factory()->role($role)->create())->get($resource::getUrl())->assertForbidden();
            }
            foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
                $actor = $role === Role::FINANCE ? $this->finance : User::factory()->role($role)->create();
                $this->actingAs($actor)->get($resource::getUrl())->assertSuccessful();
            }
        }
    }

    public function test_export_uses_filtered_rows_and_integer_amounts(): void
    {
        $service = app(CashTransactionService::class);
        $service->record($this->finance, $this->input());
        $service->record($this->finance, $this->input('Pendapatan Lain-lain'));
        $query = CashTransaction::query()->where('direction', 'OUT');
        $export = new CashTransactionsExport($query);
        $this->assertCount(1, $export->collection());
        $this->assertSame(125000, $export->collection()->first()[4]);
        $this->assertContains('Nominal (Rp)', $export->headings());
    }

    public function test_export_binds_user_text_as_strings_and_keeps_money_numeric(): void
    {
        $export = new CashTransactionsExport(CashTransaction::query());
        $sheet = new Spreadsheet;
        $description = $sheet->getActiveSheet()->getCell('F2');
        $export->bindValue($description, '=HYPERLINK("https://example.invalid","click")');
        $this->assertSame('s', $description->getDataType());
        $amount = $sheet->getActiveSheet()->getCell('E2');
        $export->bindValue($amount, 125000);
        $this->assertSame('n', $amount->getDataType());
    }

    public function test_cash_cms_creates_voids_filters_and_downloads_excel(): void
    {
        $this->actingAs($this->finance);
        $input = $this->input();
        Livewire::test(CreateCashTransaction::class)->fillForm($input)->call('create')->assertHasNoFormErrors();
        $first = CashTransaction::query()->where('uuid', $input['uuid'])->firstOrFail();
        $later = $this->input('Pendapatan Lain-lain');
        $later['date'] = today()->addDay()->toDateString();
        app(CashTransactionService::class)->record($this->finance, $later);
        $second = CashTransaction::query()->where('uuid', $later['uuid'])->firstOrFail();

        Livewire::test(ListCashTransactions::class)
            ->filterTable('tanggal', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListCashTransactions::class)
            ->filterTable('cash_category_id', $first->cash_category_id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListCashTransactions::class)->callAction('exportExcel')->assertFileDownloaded('transaksi-kas.xlsx');
        Livewire::test(ListCashTransactions::class)->callTableAction('void', $first, ['reason' => 'Salah input'])
            ->assertHasNoErrors();
        $this->assertSame('VOID', $first->fresh()->status);
    }

    public function test_cash_master_cms_can_create_update_and_delete_unused_rows(): void
    {
        $this->actingAs($this->finance);
        Livewire::test(CreateCashCategory::class)->fillForm([
            'name' => 'Biaya Uji', 'direction' => 'OUT',
            'account_id' => Account::query()->where('code', '6-1800')->value('id'), 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $category = CashCategory::query()->where('name', 'Biaya Uji')->firstOrFail();
        Livewire::test(EditCashCategory::class, ['record' => $category->id])
            ->fillForm(['name' => 'Biaya Uji Edit'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_categories', ['id' => $category->id, 'name' => 'Biaya Uji Edit']);

        Livewire::test(CreateCashAccount::class)->fillForm([
            'name' => 'Kas Setoran Uji',
            'account_id' => Account::query()->where('code', '1-1200')->value('id'), 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $cash = CashAccount::query()->where('name', 'Kas Setoran Uji')->firstOrFail();
        Livewire::test(EditCashAccount::class, ['record' => $cash->id])
            ->fillForm(['name' => 'Kas Setoran Edit'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_accounts', ['id' => $cash->id, 'name' => 'Kas Setoran Edit']);

        Livewire::test(CreateOfficeItem::class)->fillForm([
            'name' => 'Kertas Uji', 'unit' => 'rim', 'default_category_id' => $category->id, 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $item = OfficeItem::query()->where('name', 'Kertas Uji')->firstOrFail();
        Livewire::test(EditOfficeItem::class, ['record' => $item->id])
            ->fillForm(['unit' => 'pak'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('office_items', ['id' => $item->id, 'unit' => 'pak']);

        Livewire::test(ListOfficeItems::class)->callTableAction('delete', $item)->assertHasNoErrors();
        Livewire::test(ListCashCategories::class)->callTableAction('delete', $category)->assertHasNoErrors();
        Livewire::test(ListCashAccounts::class)->callTableAction('delete', $cash)->assertHasNoErrors();
        $this->assertDatabaseMissing('office_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('cash_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('cash_accounts', ['id' => $cash->id]);
    }
}
