<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $equity;

    private JournalService $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        $this->cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $this->equity = Account::query()->where('code', '3-1200')->firstOrFail();
        $this->journal = app(JournalService::class);
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
    }

    private function header(int $sourceId = 1, string $date = '2026-10-16'): array
    {
        return ['entry_date' => $date, 'description' => 'Jurnal uji', 'source_type' => 'test', 'source_id' => $sourceId, 'purpose' => 'RECORD'];
    }

    private function lines(int $amount = 1000): array
    {
        return [
            ['account_id' => $this->cash->id, 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => $this->equity->id, 'debit_minor' => 0, 'credit_minor' => $amount],
        ];
    }

    public function test_post_is_balanced_numbered_and_permanently_idempotent(): void
    {
        $first = $this->journal->post($this->header(), $this->lines());
        $this->assertSame('JU-202610-0001', $first->number);
        $this->assertSame('POSTED', $first->status);
        $this->assertCount(2, $first->lines);
        $this->assertSame($first->id, $this->journal->post($this->header(), $this->lines(999))->id);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(2, JournalLine::query()->count());
        $this->assertSame('JU-202610-0002', $this->journal->post($this->header(2), $this->lines())->number);
        $this->assertSame('JU-202611-0001', $this->journal->post($this->header(3, '2026-11-01'), $this->lines())->number);
    }

    public function test_invalid_lines_and_closed_period_write_nothing(): void
    {
        $invalid = [
            [$this->lines()[0]],
            [['account_id' => $this->cash->id, 'debit_minor' => -1, 'credit_minor' => 0], $this->lines()[1]],
            [['account_id' => $this->cash->id, 'debit_minor' => 1, 'credit_minor' => 1], $this->lines()[1]],
            [['account_id' => $this->cash->id, 'debit_minor' => 0, 'credit_minor' => 0], $this->lines()[1]],
            [$this->lines()[0], ['account_id' => $this->equity->id, 'debit_minor' => 0, 'credit_minor' => 999]],
        ];
        foreach ($invalid as $lines) {
            try {
                $this->journal->post($this->header(), $lines);
                $this->fail('Invalid journal was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, JournalEntry::query()->count());
            }
        }

        $this->cash = Account::query()->create([
            'code' => '1-9999', 'name' => 'Akun Uji', 'type' => 'ASSET',
            'normal_balance' => 'DEBIT', 'is_active' => true,
        ]);
        $this->cash->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->journal->post($this->header(), $this->lines());
    }

    public function test_closed_period_is_refused_and_reopening_is_audited(): void
    {
        $actor = auth()->user();
        $service = app(AccountingPeriodService::class);
        $period = $service->setStatus(2026, 10, 'CLOSED', $actor);
        $this->assertSame($actor->id, $period->closed_by);
        try {
            $this->journal->post($this->header(), $this->lines());
            $this->fail('Closed period was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, JournalEntry::query()->count());
        }
        $service->setStatus(2026, 10, 'OPEN', $actor, 'Koreksi jurnal uji');
        $this->assertNull($period->fresh()->closed_by);
        $this->assertSame(2, AuditLog::query()->where('subject_type', $period::class)->count());
        $this->journal->post($this->header(), $this->lines());
    }

    public function test_reversal_swaps_sides_and_cannot_repeat_or_mutate_history(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 16));
        $entry = $this->journal->post($this->header(), $this->lines());
        $reversal = $this->journal->reverse($entry, 'Koreksi');
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame(1000, $reversal->lines->first()->credit_minor);
        $this->assertSame(1000, $reversal->lines->last()->debit_minor);
        $totals = DB::table('journal_lines')->whereIn('entry_id', [$entry->id, $reversal->id])
            ->selectRaw('SUM(debit_minor) AS debit, SUM(credit_minor) AS credit')->first();
        $this->assertSame((int) $totals->debit, (int) $totals->credit);
        try {
            $this->journal->reverse($entry, 'Lagi');
            $this->fail('Second reversal was accepted.');
        } catch (ValidationException) {
            $this->assertSame(2, JournalEntry::query()->count());
        }
        $this->expectException(ValidationException::class);
        $entry->update(['description' => 'Changed']);
    }

    public function test_opening_is_one_active_journal_and_can_be_replaced_after_reversal(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 16));
        $first = $this->journal->postOpening('2026-10-16', $this->lines());
        $this->assertSame('OPENING', $first->purpose);
        $this->assertSame($first->id, $this->journal->postOpening('2026-10-16', $this->lines())->id);
        $differentMemo = $this->lines();
        $differentMemo[0]['memo'] = 'Dimensi berbeda';
        try {
            $this->journal->postOpening('2026-10-16', $differentMemo);
            $this->fail('Changed opening dimensions were treated as a replay.');
        } catch (ValidationException) {
            $this->assertSame(1, JournalEntry::query()->count());
        }
        try {
            $this->journal->postOpening('2026-10-16', $this->lines(2000));
            $this->fail('A second active opening was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, JournalEntry::query()->count());
        }
        $this->journal->reverse($first, 'Perbaikan saldo awal');
        $replacement = $this->journal->postOpening('2026-10-16', $this->lines(2000));
        $this->assertSame(2, $replacement->source_id);
        $this->assertSame(3, JournalEntry::query()->count());
    }

    public function test_generic_post_cannot_forge_opening_or_reversal(): void
    {
        $entry = $this->journal->post($this->header(), $this->lines());
        foreach ([
            ['purpose' => 'OPENING'],
            ['source_type' => 'opening_balance'],
            ['source_type' => 'Opening_Balance'],
            ['source_type' => 'opening_balance '],
            ['source_type' => 'opéning_balance'],
            ['purpose' => 'REVERSAL'],
            ['purpose' => 'reversal'],
            ['purpose' => 'OPENING '],
            ['purpose' => 'OPÉNING'],
            ['source_type' => 'journal_reversal'],
            ['source_type' => 'Journal_Reversal'],
            ['reverses_entry_id' => $entry->id],
        ] as $forged) {
            try {
                $this->journal->post([...$this->header(99), ...$forged], $this->lines());
                $this->fail('Reserved posting was accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, JournalEntry::query()->count());
            }
        }
    }

    public function test_cutover_rejects_earlier_postings_and_cannot_follow_earlier_journals(): void
    {
        $this->journal->postOpening('2026-10-16', $this->lines());
        try {
            $this->journal->post($this->header(2, '2026-10-15'), $this->lines());
            $this->fail('Pre-cutover journal was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, JournalEntry::query()->count());
        }

        $this->journal->reverse(JournalEntry::query()->where('purpose', 'OPENING')->firstOrFail(), 'Tanggal salah');
        try {
            $this->journal->post($this->header(2, '2026-10-15'), $this->lines());
            $this->fail('Journal was accepted while opening replacement is pending.');
        } catch (ValidationException) {
            $this->assertSame(2, JournalEntry::query()->count());
        }
        $this->journal->postOpening('2026-10-16', $this->lines());
    }

    public function test_cutover_cannot_follow_an_earlier_ordinary_journal(): void
    {
        $this->journal->post($this->header(2, '2026-10-15'), $this->lines());
        $this->expectException(ValidationException::class);
        $this->journal->postOpening('2026-10-16', $this->lines());
    }

    public function test_reversal_reason_length_is_validated_before_database_insert(): void
    {
        $entry = $this->journal->post($this->header(), $this->lines());
        $this->expectException(ValidationException::class);
        $this->journal->reverse($entry, str_repeat('a', 226));
    }
}
