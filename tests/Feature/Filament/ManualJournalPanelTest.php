<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\ManualJournal;
use App\Filament\Resources\Journals\JournalResource;
use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ManualJournalPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, ['view']);
        $this->travelTo(now()->setDate(2026, 10, 18));
    }

    private function lines(int $amount = 12500, ?int $cartId = null): array
    {
        return [
            [
                'account_id' => Account::query()->where('code', '1-1700')->firstOrFail()->id,
                'debit_minor' => $amount, 'credit_minor' => 0, 'cart_id' => $cartId,
            ],
            [
                'account_id' => Account::query()->where('code', '2-1400')->firstOrFail()->id,
                'debit_minor' => 0, 'credit_minor' => $amount, 'cart_id' => null,
            ],
        ];
    }

    public function test_finance_and_management_can_post_balanced_journals_with_optional_cart_and_audit(): void
    {
        $cart = Cart::query()->create(['code' => 'T-029', 'status' => 'retired']);
        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            $this->get(ManualJournal::getUrl())->assertSuccessful()
                ->assertSee('1-1700')->assertSee('2-1400')
                ->assertSee('T-029')
                ->assertSee('Isi nominal dulu');
            Livewire::test(ListJournals::class)->assertSee('Jurnal Manual');

            Livewire::test(ManualJournal::class)
                ->set('entryDate', '2026-10-18')
                ->set('description', 'Koreksi stok bahan')
                ->set('lines', $this->lines(12500, $cart->id))
                ->assertSee('12.500')
                ->call('submit')->assertHasNoErrors();

            $entry = JournalEntry::query()->where('source_type', 'manual')->where('posted_by', $actor->id)->firstOrFail();
            $this->assertSame('MANUAL', $entry->purpose);
            $this->assertSame('Koreksi stok bahan', $entry->description);
            $this->assertSame(12500, (int) $entry->lines()->sum('debit_minor'));
            $this->assertSame(12500, (int) $entry->lines()->sum('credit_minor'));
            $this->assertSame($cart->id, $entry->lines()->firstOrFail()->cart_id);
            $this->assertDatabaseHas('audit_log', [
                'action' => 'journal.manual.posted', 'subject_type' => JournalEntry::class,
                'subject_id' => $entry->id, 'actor_id' => $actor->id, 'actor_role' => $role->value,
            ]);
        }
    }

    public function test_unbalanced_and_invalid_lines_are_rejected_without_audit(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines(12500))
            ->set('lines.1.credit_minor', 10000)
            ->assertSee('Belum seimbang')
            ->call('submit')->assertHasErrors(['lines']);

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', [$this->lines()[0]])
            ->call('submit')->assertHasErrors(['lines']);

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', [
                ['account_id' => $this->lines()[0]['account_id'], 'debit_minor' => 0, 'credit_minor' => 0],
                ['account_id' => $this->lines()[1]['account_id'], 'debit_minor' => 0, 'credit_minor' => 0],
            ])
            ->call('submit')->assertHasErrors(['lines.0'])
            ->assertSee('Tepat satu sisi debit atau kredit harus lebih dari nol.');

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines())
            ->set('lines.0.debit_minor', '12.5')
            ->call('submit')->assertHasErrors(['lines.0.debit_minor']);

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines())
            ->set('lines.0.debit_minor', '999999999999999999999999')
            ->call('submit')->assertHasErrors(['lines.0.debit_minor']);

        $inactive = Account::query()->create([
            'code' => '6-2900', 'name' => 'Akun lama', 'type' => 'EXPENSE',
            'normal_balance' => 'DEBIT', 'is_active' => false,
        ]);
        $lines = $this->lines();
        $lines[0]['account_id'] = $inactive->id;
        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $lines)
            ->call('submit')->assertHasErrors(['lines.0.account_id']);

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'journal.manual.posted')->count());
    }

    public function test_closed_period_rejects_manual_posting(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 10, 'status' => 'CLOSED']);

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines())
            ->call('submit')->assertHasErrors(['entry_date']);

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'journal.manual.posted')->count());
    }

    public function test_cleared_optional_cart_dimension_is_accepted(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $lines = $this->lines();
        $lines[0]['cart_id'] = '';

        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $lines)
            ->call('submit')->assertHasNoErrors();

        $this->assertNull(JournalEntry::query()->where('source_type', 'manual')
            ->firstOrFail()->lines()->firstOrFail()->cart_id);
    }

    public function test_repeated_submit_keeps_one_journal_and_one_audit_row(): void
    {
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page = Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines());
        $sourceId = $page->get('sourceId');
        $page->call('submit')->assertHasNoErrors();
        $page->call('submit')->assertHasNoErrors();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'manual')
            ->where('source_id', $sourceId)->where('purpose', 'MANUAL')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'journal.manual.posted')->count());
    }

    public function test_existing_reversal_action_reverses_manual_once_and_audits_it(): void
    {
        $actor = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($actor);
        Livewire::test(ManualJournal::class)
            ->set('description', 'Koreksi')
            ->set('lines', $this->lines())
            ->call('submit')->assertHasNoErrors();
        $entry = JournalEntry::query()->where('source_type', 'manual')->firstOrFail();

        Livewire::test(ListJournals::class)->callTableAction('reverse', $entry, ['reason' => 'Salah akun'])
            ->assertHasNoErrors();
        $reversal = $entry->fresh()->reversal;
        $this->assertNotNull($reversal);
        $this->assertSame('REVERSAL', $reversal->purpose);
        $this->assertSame('journal_reversal', $reversal->source_type);
        $this->assertSame(12500, (int) $reversal->lines()->sum('credit_minor'));
        $this->assertDatabaseHas('audit_log', [
            'action' => 'journal.reversed', 'subject_type' => JournalEntry::class,
            'subject_id' => $entry->id, 'actor_id' => $actor->id,
        ]);
        Livewire::test(ListJournals::class)->assertTableActionHidden('reverse', $entry);
        try {
            app(JournalService::class)->reverse($entry, 'Coba lagi');
            $this->fail('Second reversal must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal', $exception->errors());
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'journal.reversed')->count());
    }

    public function test_administrator_can_post_and_reverse_while_other_roles_get_403(): void
    {
        $entry = app(JournalService::class)->post([
            'entry_date' => '2026-10-18', 'description' => 'Modul kas',
            'source_type' => 'cash_transaction', 'source_id' => 17, 'purpose' => 'CASH_TX',
        ], $this->lines());

        $finance = User::factory()->role(Role::FINANCE)->create();
        $administrator = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($administrator);
        $this->get(JournalResource::getUrl())->assertSuccessful();
        $this->get(JournalResource::getUrl('view', ['record' => $entry]))->assertSuccessful();
        Livewire::test(ListJournals::class)->assertSee('Jurnal Manual')
            ->assertTableActionVisible('reverse', $entry);
        $this->get(ManualJournal::getUrl())->assertOk();
        $this->actingAs($finance);
        $page = Livewire::test(ManualJournal::class);
        $this->actingAs($administrator);
        $page->call('submit')->assertHasErrors();
        $this->assertFalse(JournalResource::canEdit($entry));
        $this->assertFalse(JournalResource::canDelete($entry));

        foreach ([Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            PermissionMatrix::set($role, PanelModule::ATTENDANCE, ['view']);
            $wrongRole = User::factory()->role($role)->create();
            $this->actingAs($wrongRole);
            $this->get(ManualJournal::getUrl())->assertForbidden();
            $this->actingAs($finance);
            $page = Livewire::test(ManualJournal::class);
            $this->actingAs($wrongRole);
            $page->call('submit')->assertForbidden();
        }

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'journal.manual.posted')->count());
    }

    public function test_journals_from_other_modules_have_no_edit_or_delete_actions(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        foreach (['settlement' => 'SETTLEMENT', 'cash_transaction' => 'CASH_TX', 'payroll' => 'PAYROLL'] as $source => $purpose) {
            $entry = app(JournalService::class)->post([
                'entry_date' => '2026-10-18', 'description' => 'Dari modul '.$source,
                'source_type' => $source, 'source_id' => 29, 'purpose' => $purpose,
            ], $this->lines());
            $this->assertFalse(JournalResource::canEdit($entry));
            $this->assertFalse(JournalResource::canDelete($entry));
            $this->get(JournalResource::getUrl('view', ['record' => $entry]))->assertSuccessful();
            Livewire::test(ListJournals::class)->assertTableActionVisible('reverse', $entry);
        }
        $this->assertSame(3, JournalEntry::query()->count());
    }
}
